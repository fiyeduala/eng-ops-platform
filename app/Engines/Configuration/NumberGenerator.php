<?php

declare(strict_types=1);

namespace App\Engines\Configuration;

use App\Engines\Access\OrganizationContext;
use App\Models\NumberingScheme;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues the reference number a company puts on paper (§64).
 *
 * Three properties, in the order they matter:
 *
 *   1. UNIQUE. A duplicate project number is not a cosmetic defect — it is two contracts that
 *      cannot be told apart in an email thread. The counter is incremented inside a transaction
 *      holding a row lock, and the unique key on (scheme, period) catches anything that somehow
 *      raced past it.
 *   2. CONFIGURABLE. The pattern is a per-tenant string, not a sprintf() buried in a service.
 *   3. STABLE. A number, once issued, is never recomputed. Callers store the string; nothing in
 *      the application derives a number from the record's current attributes, because the project
 *      that moved department in March must still be the project everyone quoted in January.
 *
 * Numbers are consumed even when the surrounding transaction later rolls back, leaving a gap.
 * That is deliberate. The alternative — reusing a released number — means two different records
 * can bear the same reference at different times, which is worse than a gap in every audit
 * conversation that has ever happened.
 */
class NumberGenerator
{
    /**
     * Tokens the pattern may contain, beyond the caller's own.
     *
     * {SEQ} may carry an explicit width, e.g. {SEQ:6}, which overrides the scheme's padding.
     */
    private const SEQUENCE_TOKEN = '/\{SEQ(?::(\d+))?\}/';

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The next reference for a module, e.g. next('project') -> "PRJ-2026-0007".
     *
     * @param  array<string,string|int|null>  $tokens  extra pattern tokens, e.g. ['PROJECT' => 'ALPHA']
     */
    public function next(string $entityType, array $tokens = []): string
    {
        $scheme = $this->schemeFor($entityType);
        $moment = now();
        $periodKey = $scheme->periodKeyFor($moment);

        $sequence = $this->claim($scheme, $periodKey);

        return $this->render($scheme, $sequence, $moment, $tokens);
    }

    /**
     * What the next reference WOULD be, without consuming it.
     *
     * For preview fields only. Two people on a create form see the same preview and only one of
     * them gets that number, so nothing may be persisted from this.
     *
     * @param  array<string,string|int|null>  $tokens
     */
    public function preview(string $entityType, array $tokens = []): string
    {
        return $this->previewScheme($this->schemeFor($entityType), $tokens);
    }

    /**
     * What a given scheme WOULD produce — including one carrying unsaved edits.
     *
     * The numbering editor has to show an administrator the consequence of a pattern BEFORE they
     * commit to it, and the rendering rules are subtle enough — per-token widths, unresolved
     * tokens dropped, the separators around them collapsed — that a second implementation living
     * in the editor would eventually disagree with this one about what a number looks like. So the
     * editor hands a modified scheme instance here instead of rendering it itself.
     *
     * The counter used is the LIVE one, so the example is the number that would actually come out
     * next rather than a hypothetical first issue. Nothing is consumed.
     *
     * @param  array<string,string|int|null>  $tokens
     */
    public function previewScheme(NumberingScheme $scheme, array $tokens = []): string
    {
        $moment = now();

        $next = $scheme->exists
            ? DB::table('number_sequences')
                ->where('numbering_scheme_id', $scheme->getKey())
                ->where('period_key', $scheme->periodKeyFor($moment))
                ->value('next_value')
            : null;

        return $this->render($scheme, (int) ($next ?? $scheme->start_at), $moment, $tokens);
    }

    /**
     * The scheme for a module, provisioning the tenant's default if it has none.
     *
     * Auto-provisioning rather than throwing: a company that has never opened the numbering
     * screen must still be able to create a project. What it gets is an ordinary editable row,
     * not a hidden fallback — so the first visit to the Phase 7 builder shows the scheme actually
     * in use, with its counter, rather than an empty page and numbers coming from nowhere.
     */
    public function schemeFor(string $entityType): NumberingScheme
    {
        $existing = NumberingScheme::query()->forEntity($entityType)->first();

        if ($existing !== null) {
            return $existing;
        }

        $default = self::defaults()[$entityType] ?? [
            'prefix' => Str::upper(Str::substr(str_replace('_', '', $entityType), 0, 3)),
            'pattern' => '{PREFIX}-{YYYY}-{SEQ}',
            'reset_period' => 'yearly',
            'padding' => 4,
        ];

        return NumberingScheme::create([
            'organization_id' => $this->context->idOrFail(),
            'entity_type' => $entityType,
            'label' => Str::headline($entityType).' numbering',
            'prefix' => $default['prefix'],
            'pattern' => $default['pattern'],
            'reset_period' => $default['reset_period'],
            'padding' => $default['padding'],
            'start_at' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * Take the next value off the counter, atomically.
     *
     * The retry exists for the row that does not exist yet: two concurrent first-of-the-year
     * creations both find nothing, both insert, and one loses on the unique key. The loser
     * re-reads under the lock the winner has now created and proceeds normally.
     */
    private function claim(NumberingScheme $scheme, string $periodKey): int
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $claimed = DB::transaction(function () use ($scheme, $periodKey) {
                $row = DB::table('number_sequences')
                    ->where('numbering_scheme_id', $scheme->id)
                    ->where('period_key', $periodKey)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    return null;   // insert it outside the lock, then come back round
                }

                DB::table('number_sequences')
                    ->where('id', $row->id)
                    ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

                return (int) $row->next_value;
            });

            if ($claimed !== null) {
                return $claimed;
            }

            try {
                DB::table('number_sequences')->insert([
                    'organization_id' => $scheme->organization_id,
                    'numbering_scheme_id' => $scheme->id,
                    'period_key' => $periodKey,
                    'next_value' => $scheme->start_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                // Somebody else created it first. The next pass reads theirs.
            }
        }

        throw new \RuntimeException(
            "Could not claim a number for [{$scheme->entity_type}] after three attempts."
        );
    }

    /**
     * @param  array<string,string|int|null>  $tokens
     */
    private function render(NumberingScheme $scheme, int $sequence, \DateTimeInterface $moment, array $tokens): string
    {
        $pattern = (string) $scheme->pattern;

        // {SEQ} first, because its width may be carried in the token itself.
        $pattern = preg_replace_callback(
            self::SEQUENCE_TOKEN,
            fn (array $m) => str_pad(
                (string) $sequence,
                (int) ($m[1] ?? $scheme->padding),
                '0',
                STR_PAD_LEFT
            ),
            $pattern,
        ) ?? $pattern;

        $replacements = [
            'ORG' => $this->organizationToken(),
            'PREFIX' => (string) ($scheme->prefix ?? ''),
            'YYYY' => $moment->format('Y'),
            'YY' => $moment->format('y'),
            'MM' => $moment->format('m'),
            ...array_map(fn ($value) => (string) ($value ?? ''), $tokens),
        ];

        foreach ($replacements as $token => $value) {
            $pattern = str_replace('{'.$token.'}', $value, $pattern);
        }

        // Any token the pattern named but nobody supplied is dropped, and the separators that
        // surrounded it collapse — otherwise a scheme mentioning {DISCIPLINE} on a module that
        // has no discipline would issue "PRJ--2026--0007".
        $pattern = preg_replace('/\{[A-Z_]+(?::\d+)?\}/', '', $pattern) ?? $pattern;
        $pattern = preg_replace('/([-\/._])\1+/', '$1', $pattern) ?? $pattern;

        return trim($pattern, '-/._ ');
    }

    /**
     * The company's short code, for patterns that lead with it.
     *
     * Read from settings rather than derived from the name, because "Meridian Engineering
     * Services Ltd" has no obvious three letters and the company already knows what it calls
     * itself on a drawing.
     */
    private function organizationToken(): string
    {
        return Str::upper($this->settings->string('org.short_code', ''));
    }

    /**
     * Starting patterns for the modules that exist. Every one of them is editable afterwards.
     *
     * @return array<string,array{prefix:string,pattern:string,reset_period:string,padding:int}>
     */
    public static function defaults(): array
    {
        return [
            'project' => ['prefix' => 'PRJ', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'task' => ['prefix' => 'TSK', 'pattern' => '{PREFIX}-{SEQ}', 'reset_period' => 'none', 'padding' => 6],
            'meeting' => ['prefix' => 'MTG', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'memo' => ['prefix' => 'MEMO', 'pattern' => '{PREFIX}/{YYYY}/{SEQ}', 'reset_period' => 'yearly', 'padding' => 3],
            'announcement' => ['prefix' => 'ANN', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 3],
            // Documents number per year and never reset per project: a document number is quoted on
            // drawings, in transmittals and in client correspondence, so it must be unique across
            // the whole company for as long as the company keeps records. A category may override
            // the pattern (see document_categories.numbering_pattern) for registers that follow a
            // client's own convention.
            'document' => ['prefix' => 'DOC', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'transmittal' => ['prefix' => 'TRN', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            // --- Phase 4: engineering, HSE and quality registers ----------------------
            // Every one of these would be auto-provisioned by schemeFor() anyway; they are listed
            // to give each register the abbreviation its industry actually uses, because the
            // number is what people say out loud in a meeting.
            'deliverable' => ['prefix' => 'MDR', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // Drawing numbers are the one series that does NOT reset. A drawing number is stamped
            // in a title block, quoted in a transmittal and referenced in as-builts a decade later;
            // a year-resetting series would eventually issue the same number twice.
            'drawing' => ['prefix' => 'DWG', 'pattern' => '{PREFIX}-{SEQ}', 'reset_period' => 'none', 'padding' => 5],
            // Three series, one register. An RFI, a technical query and a site query live in the
            // same table and share a status vocabulary, but an engineer quoting "TQ-2026-0031"
            // means a specific document — so the numbers must not interleave. See
            // RegisterService::numberingEntityType().
            'rfi' => ['prefix' => 'RFI', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'technical_query' => ['prefix' => 'TQ', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'site_query' => ['prefix' => 'SQ', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'submittal' => ['prefix' => 'SUB', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'change_request' => ['prefix' => 'ECR', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            // --- HSE ------------------------------------------------------------------
            // Incidents number per year because every statutory return, insurance renewal and
            // safety statistic is annual: "INC-2026-0012" is the twelfth this year, which is the
            // figure a safety manager is asked for.
            'hse_incident' => ['prefix' => 'INC', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'investigation' => ['prefix' => 'INV', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // Corrective actions do NOT reset. An action opened in December and closed in March
            // spans the boundary, and the register is read as one continuous backlog rather than
            // as a set of annual cohorts — a year-resetting series would make "CA-0007" ambiguous
            // in exactly the conversation where somebody is chasing it.
            'corrective_action' => ['prefix' => 'CA', 'pattern' => '{PREFIX}-{SEQ}', 'reset_period' => 'none', 'padding' => 5],
            'hse_observation' => ['prefix' => 'OBS', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'hse_risk' => ['prefix' => 'RA', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'hse_inspection' => ['prefix' => 'HSI', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'toolbox_talk' => ['prefix' => 'TBT', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // Permits reset MONTHLY and pad to three, because a large site raises dozens a week and
            // an annual series reaches five digits by autumn. "PTW-2026-08-041" is a number a
            // control room can read off a board.
            'permit' => ['prefix' => 'PTW', 'pattern' => '{PREFIX}-{YYYY}-{MM}-{SEQ}', 'reset_period' => 'monthly', 'padding' => 3],
            'hse_training' => ['prefix' => 'TRN', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            // --- quality --------------------------------------------------------------
            // The industry says "RFI" for a request for inspection too, which is exactly why this
            // series is ITP-prefixed instead: two registers quoting the same abbreviation in the
            // same meeting is a defect in the numbering, not a charming ambiguity.
            'quality_inspection' => ['prefix' => 'ITP', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'ncr' => ['prefix' => 'NCR', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            // --- Phase 5: the commercial foundation -----------------------------------
            // Vendors and clients do NOT reset. A vendor code is quoted on every purchase order
            // ever raised against them and referenced by finance a decade later; an annual series
            // would eventually issue VEN-0007 twice and make a payment enquiry ambiguous in exactly
            // the conversation where somebody is chasing money.
            'vendor' => ['prefix' => 'VEN', 'pattern' => '{PREFIX}-{SEQ}', 'reset_period' => 'none', 'padding' => 4],
            'client' => ['prefix' => 'CLI', 'pattern' => '{PREFIX}-{SEQ}', 'reset_period' => 'none', 'padding' => 4],
            // Contracts DO reset, because a contract is an event with a year attached and every
            // commercial review is annual. "CTR-2026-0004" is how it is quoted in a meeting.
            'contract' => ['prefix' => 'CTR', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            // --- Phase 5, part 2: the procurement chain -------------------------------
            // ALL FIVE RESET YEARLY, because every one of them is an event with a date and every
            // procurement review, audit and budget line is annual. "PO-2026-0184" answers "how many
            // orders have we raised this year" by being read aloud, which is worth more than the
            // continuity a non-resetting series would buy.
            //
            // FIVE PREFIXES, NOT ONE. It is tempting to number the whole chain PRC-2026-0001 and
            // let the type disambiguate — and then a receipt and the order it arrived against share
            // a number, and every conversation about either one needs a qualifier. Each register is
            // quoted independently by a different person for a different reason, so each gets its
            // own series.
            'purchase_request' => ['prefix' => 'PR', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'rfq' => ['prefix' => 'RFQ', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // A quotation number is OURS, not the supplier's. Theirs is kept verbatim in
            // `quotations.reference`, because a supplier chasing payment quotes their own number and
            // a buyer chasing a decision quotes this one, and neither should have to translate.
            'quotation' => ['prefix' => 'QTN', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'purchase_order' => ['prefix' => 'PO', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // GRN, not GR: the abbreviation every storekeeper already writes on the paper copy.
            'goods_receipt' => ['prefix' => 'GRN', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            /*
             * --- Phase 5, part 3: inventory ------------------------------------------
             *
             * A LEDGER SERIES IS SIX DIGITS AND THE OTHERS ARE FOUR. A busy store posts more
             * movements in a month than the company raises purchase orders in a decade — every
             * receipt line, every issue, both halves of every transfer — and a four-digit series
             * that wraps in March is a series in which two movements share a number.
             *
             * WAREHOUSES AND STOCK ITEMS ARE ABSENT, and their absence is the point: their codes are
             * TYPED BY A HUMAN. A storekeeper calls the main store WH-MAIN and the catalogue line
             * for a 12in flange whatever their industry already calls it, and a generated
             * `WHS-2026-0001` would be a code nobody uses next to a name everybody does. Generated
             * numbers are for records that are EVENTS; typed codes are for things that exist.
             */
            'stock_transaction' => ['prefix' => 'STK', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 6],
            // MR, and deliberately not MRQ or MREQ: it is what is said out loud in a store, and the
            // register it is most confused with — PR — is two letters as well.
            'material_request' => ['prefix' => 'MR', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            /*
             * --- Phase 5, part 4: assets and fleet ------------------------------------
             *
             * ONE ENTRY, NOT FIVE, and the four that are missing are missing for the reason stated
             * above the inventory block. An asset code is TYPED: a plant manager calls the 250kVA
             * set GEN-02 because that is what is stencilled on its side, and a vehicle is found by
             * its plate, which the state issued and we may not invent. A fuel log is identified by
             * the receipt in the driver's hand, and a vehicle document by the certificate number
             * printed on it — both are somebody else's number, recorded verbatim.
             *
             * A maintenance job is the one EVENT in this part: nothing outside the system issues it
             * a number, the fitter needs something to write on the job card, and a service in March
             * and the same service in September must be told apart. MNT rather than WO, because
             * `work_order_reference` on the same row is where a genuine external work-order number
             * goes, and two things called WO would be read as one.
             */
            'asset_maintenance' => ['prefix' => 'MNT', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],

            // --- Phase 5, part 5: operational finance --------------------------------
            'project_cost' => ['prefix' => 'CST', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 6],
            'budget' => ['prefix' => 'BUD', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // Every one of these is an EVENT the company originates, so every one is numbered. An
            // income row's own number is not the invoice number the client sees — that is typed
            // into `invoice_reference`, because many companies invoice from an accounting package.
            'expense' => ['prefix' => 'EXP', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'income' => ['prefix' => 'INC', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'payment' => ['prefix' => 'PAY', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'petty_cash_transaction' => ['prefix' => 'PCV', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],

            // --- Phase 6: people ------------------------------------------------------
            'leave_request' => ['prefix' => 'LV', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'timesheet' => ['prefix' => 'TS', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'overtime_request' => ['prefix' => 'OT', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            // A vacancy is quoted in job adverts and in the approval that created it; an applicant
            // reference is what recruitment correspondence uses INSTEAD of the person's name, which
            // is the point of numbering them at all.
            'vacancy' => ['prefix' => 'VAC', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'applicant' => ['prefix' => 'APP', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'employee_exit' => ['prefix' => 'EXT', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            'performance_review' => ['prefix' => 'PRV', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
            'employee_movement' => ['prefix' => 'MOV', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // A disciplinary case is referred to BY ITS NUMBER in every letter the company sends
            // about it, which is the point: correspondence that says "DSC-2026-0003" rather than
            // naming the allegation can be filed, copied and chased without repeating it.
            'disciplinary_case' => ['prefix' => 'DSC', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 4],
            // A payroll run resets MONTHLY rather than yearly, because "PRL-2026-09-0001" reads as
            // the run for that month and a correction to it is PRL-2026-09-0002 — the two sit
            // together in any listing sorted by number, which is exactly where somebody looking for
            // "what did we actually pay in September" needs them to be.
            'payroll_run' => ['prefix' => 'PRL', 'pattern' => '{PREFIX}-{YYYY}-{MM}-{SEQ}', 'reset_period' => 'monthly', 'padding' => 4],

            // Five digits and a monthly reset: a busy reception books more visitors in a year than
            // the company raises purchase orders in five, and VIS-2026-09-00001 stays readable where
            // a yearly sequence would be into six figures by November.
            'visitor' => ['prefix' => 'VIS', 'pattern' => '{PREFIX}-{YYYY}-{MM}-{SEQ}', 'reset_period' => 'monthly', 'padding' => 5],

            /*
             * --- Phase 7: the form builder --------------------------------------------
             *
             * ONE SERIES FOR EVERY FORM IN THE COMPANY, and the alternative was seriously
             * considered: a scheme per form, so that scaffold inspections run SCAF-2026-0001 and
             * sign-ins run VIS-2026-0001 on their own counters. It is rejected because the set of
             * forms is COMPANY-CREATED AND UNBOUNDED. A scheme per form means the numbering screen
             * grows a row every time somebody drafts anything, a form deleted in March leaves an
             * orphan scheme holding a live counter, and a form renamed leaves a series prefixed
             * with what it used to be called. None of that is visible to the person it happens to.
             *
             * What a company that wants the form named in the number does instead is add {FORM} to
             * this pattern — FormSubmissionService passes the form's key as a token on every
             * issue, so it resolves without a line of code. That is the configurability doctrine
             * doing the work: ship the plain series, leave the richer one reachable from the
             * numbering screen (§64, §73).
             */
            'form_submission' => ['prefix' => 'FRM', 'pattern' => '{PREFIX}-{YYYY}-{SEQ}', 'reset_period' => 'yearly', 'padding' => 5],
        ];
    }
}
