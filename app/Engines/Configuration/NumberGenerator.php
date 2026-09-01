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
        $scheme = $this->schemeFor($entityType);
        $moment = now();

        $next = DB::table('number_sequences')
            ->where('numbering_scheme_id', $scheme->id)
            ->where('period_key', $scheme->periodKeyFor($moment))
            ->value('next_value');

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
        ];
    }
}
