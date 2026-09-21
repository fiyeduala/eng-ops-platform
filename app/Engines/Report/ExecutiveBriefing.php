<?php

declare(strict_types=1);

namespace App\Engines\Report;

use App\Engines\Configuration\SettingsRepository;
use App\Engines\Dashboard\RenderedWidget;
use App\Engines\Dashboard\WidgetComposer;
use App\Engines\Document\PdfRenderer;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Models\Export;
use App\Models\Role;
use App\Models\User;
use App\Support\WidgetCatalogue;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The daily executive report (§55, docs/11 §6).
 *
 * THIS IS THE EXECUTIVE BOARD, PRINTED, and choosing that over eight hand-written sections is the
 * whole design. docs/11 §6 lists People, Projects, Engineering, HSE, Procurement, Finance,
 * Inventory and Fleet, and a literal reading of that list is eight bespoke queries that duplicate
 * what the KPI engine already counts, drift from it within a release, and cannot be changed by the
 * company whose report it is. Running `WidgetComposer` instead means the sections ARE the tiles on
 * the executive board: a company adds a measure to its report by putting it on the board, through
 * the screens, with no code (§73), and there is one place in this platform where a figure is
 * counted rather than two (§78's argument, applied to numbers instead of approvals).
 *
 * EACH COPY IS COMPOSED FOR ONE PERSON AND IS ONLY THAT PERSON'S. This is the requirement §55
 * actually turns on, and it is why the report cannot be rendered once and mailed to a list. The
 * composer re-checks the board permission with no middleware in front of it, counts every figure
 * through that reader's own reach, and refuses a stored snapshot to anybody whose reach does not
 * contain the scope it was counted over — so a Finance Manager and an HSE Manager receive different
 * documents from one schedule, correctly, and neither is ever shown a number derived from rows they
 * could not open.
 *
 * WHY THE FILE IS AN EXPORT ROW AND NOT A DOCUMENT. docs/11 §6 says the reports are "archived as
 * documents so they remain auditable", and that is the one instruction here that is not followed —
 * deliberately. A document lives in a register that organization-wide `document.view` can read, so
 * archiving eighteen personalised copies as documents would publish to the whole company exactly
 * the per-recipient figures the previous paragraph exists to keep apart. It would defeat §55 in the
 * name of §55. The `exports` table already means the right thing: one person's file, on the private
 * disk, behind an ownership policy, reachable only through a signed short-lived link — and the ROW
 * OUTLIVES THE FILE, which is the audit. After retention, what remains is a permanent record that
 * this person was sent this briefing on this date, and the figures themselves are reproducible from
 * a board that is configuration. See docs/11 §6, which now says so.
 *
 * NOTHING IS SENT TO SOMEBODY WITH AN EMPTY BOARD. A briefing with no tiles on it is not news that
 * the company had a quiet day; it is a company that has not arranged its executive board, or a
 * reader whose permissions do not reach any of it. Sending a blank PDF every morning at seven is
 * how a scheduled report teaches its recipients to filter it.
 */
class ExecutiveBriefing
{
    /** Which board is printed. Named rather than passed, because there is exactly one answer. */
    public const BOARD = 'executive';

    /** What the `exports` row calls this, so a briefing is distinguishable from an ad-hoc export. */
    public const ENTITY_TYPE = 'executive_briefing';

    /**
     * Fallbacks for a company whose settings were never provisioned.
     *
     * Conservative in the same direction as ExportManager's: a platform that cannot read its own
     * configuration keeps files for less time, not more.
     */
    public const DEFAULT_RETENTION_DAYS = 14;

    /**
     * The most briefings one company produces in one run.
     *
     * Not a policy about who may receive it — a ceiling on what one cron minute may cost. Each copy
     * is a full composition of the board plus a PDF, and §1 puts this on shared hosting with a
     * `max_execution_time` nobody here controls. A company that genuinely has more than this many
     * people holding `dashboard.view_executive` has a permissions problem the report should not
     * silently absorb, and the command says so rather than truncating quietly.
     */
    public const MAX_RECIPIENTS = 40;

    public function __construct(
        private readonly WidgetComposer $composer,
        private readonly PdfRenderer $pdf,
        private readonly SettingsRepository $settings,
        private readonly NotificationDispatcher $notifications,
    ) {}

    // ================================================================== configuration

    public function isEnabled(?int $organizationId = null): bool
    {
        return $this->settings->bool('report.daily_executive_enabled', false, $organizationId);
    }

    /** The hour, in the company's own timezone, at which the briefing goes out. */
    public function hour(?int $organizationId = null): int
    {
        return max(0, min(23, $this->settings->int('report.daily_executive_hour', 7, $organizationId)));
    }

    public function retentionDays(?int $organizationId = null): int
    {
        $configured = $this->settings->int(
            'report.daily_executive_retention_days',
            self::DEFAULT_RETENTION_DAYS,
            $organizationId,
        );

        return max(1, min(90, $configured));
    }

    // ================================================================== who gets one

    /**
     * Everybody in the current tenant who may open the executive board.
     *
     * Read off the permission rather than a role name, because §5 makes the role names the
     * company's to choose and "Managing Director" is not a stable identifier. Inactive people and
     * inactive roles are excluded here as well as by the dispatcher: a locked account is not a
     * recipient, and producing the file first and discarding it afterwards would mean composing a
     * board for somebody who cannot sign in.
     *
     * @return array<int,User>
     */
    public function recipients(): array
    {
        $permission = WidgetCatalogue::permissionForBoard(self::BOARD);

        if ($permission === null) {
            return [];
        }

        $ids = Role::query()
            ->active()
            // `permissions.key`, QUALIFIED AND SPELLED EXACTLY SO. The catalogue column is `key`,
            // not `name`, and an unqualified `name` inside this correlated subquery resolves to
            // `roles.name` instead — which is a valid query that compares a role's title with a
            // permission string and therefore matches nobody, silently, forever.
            ->whereHas('permissions', fn ($query) => $query->where('permissions.key', $permission))
            ->with('users:id')
            ->get()
            ->flatMap(fn (Role $role) => $role->users->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->active()
            ->whereKey($ids)
            // §89. The composer asks each viewer for their permissions and their organization, and a
            // relation loaded per recipient is one more query per person on a cron-timed run.
            ->with('organization')
            ->orderBy('name')
            ->get()
            ->all();
    }

    // ================================================================== producing one

    /**
     * The name every copy of one day's briefing carries.
     *
     * Deterministic on purpose: it is what makes a re-run idempotent without a column to track it.
     * Two runs on the same day find the same row and the second one does nothing (docs/11 §7's rule
     * for every scheduler-driven engine here).
     */
    public function nameFor(CarbonInterface $asOf): string
    {
        return 'Daily executive report — '.$asOf->format('j M Y');
    }

    /**
     * What this person's copy will contain.
     *
     * Split out from the PDF for two reasons. It is the seam a test can assert against — what
     * matters about §55 is that two readers' copies DIFFER, and a Dompdf stream is not a thing you
     * can read a number out of. And it names the one expensive call in the whole engine, so the
     * emptiness check below happens before a PDF is rendered rather than after.
     *
     * @return array<int,RenderedWidget>
     */
    public function tilesFor(User $recipient, CarbonInterface $asOf): array
    {
        return $this->composer->compose(self::BOARD, $recipient, null, $asOf);
    }

    /**
     * The body of one person's briefing, as HTML.
     *
     * @param  array<int,RenderedWidget>  $tiles
     */
    public function bodyFor(User $recipient, CarbonInterface $asOf, array $tiles): string
    {
        return view('reports.daily-executive', [
            'recipient' => $recipient,
            'organization' => $recipient->organization,
            'tiles' => $tiles,
            'asOf' => $asOf,
        ])->render();
    }

    /**
     * One person's briefing, stored and recorded, or null where there was nothing to send them.
     *
     * Null is returned for a reader with no tiles — see the class docblock — and for a day this
     * person has already been sent. It is not an error in either case, and the command counts it
     * separately, because "nobody has arranged the board" and "it already went out" both need to be
     * distinguishable from a send when read off the console.
     */
    public function briefingFor(User $recipient, CarbonInterface $asOf, bool $force = false): ?Export
    {
        $name = $this->nameFor($asOf);

        $existing = Export::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('requested_by', $recipient->getKey())
            ->where('name', $name)
            ->first();

        if ($existing !== null && ! $force) {
            return null;
        }

        $tiles = $this->tilesFor($recipient, $asOf);

        if ($tiles === []) {
            return null;
        }

        $bytes = $this->pdf->renderHtml(
            title: $name,
            bodyHtml: $this->bodyFor($recipient, $asOf, $tiles),
            footerHtml: e($name).' &nbsp;|&nbsp; '.e((string) $recipient->organization?->name)
                .' &nbsp;|&nbsp; Shows only what '.e($recipient->name).' is permitted to see.',
        );

        // A generated path, for the reason ExportManager gives at length: a company-entered name is
        // a display label and never a path segment (§67, §84).
        $path = 'exports/'.$recipient->organization_id.'/'.Str::uuid()->toString().'.pdf';

        Storage::disk(Export::DISK)->put($path, $bytes);

        $export = $existing ?? new Export;

        $export->forceFill([
            // §74: from the recipient, never from anything that came in from outside.
            'organization_id' => $recipient->organization_id,
            'requested_by' => $recipient->getKey(),
            'report_definition_id' => null,
            'name' => $name,
            'entity_type' => self::ENTITY_TYPE,
            'format' => 'pdf',
            'status' => 'ready',
            'filters' => null,
            'ad_hoc_filters' => null,
            'filename' => $this->filename($asOf),
            'path' => $path,
            'bytes' => strlen($bytes),
            'rows' => count($tiles),
            'truncated' => false,
            'started_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addDays($this->retentionDays((int) $recipient->organization_id)),
            'failure_reason' => null,
        ])->save();

        return $export;
    }

    /**
     * Tell one person their briefing is waiting.
     *
     * The link is signed for exactly as long as the file exists, so the notice and the retention
     * setting cannot drift apart: shortening retention shortens every link already sent. The notice
     * itself carries no figures — §68, and more pointedly here than anywhere else, because the one
     * thing every copy of this report has in common is that it is not the same as anybody else's.
     */
    public function announce(Export $export, User $recipient, CarbonInterface $asOf): void
    {
        $this->notifications->dispatch(
            NotificationMessage::make(
                key: 'report.daily_executive',
                title: 'Your daily report for '.$asOf->format('j M'),
                body: 'The executive board as it stood this morning, counted through your own access. '
                    .'It is kept until '.$export->expires_at?->format('j M Y').'.',
                actionUrl: URL::temporarySignedRoute(
                    'exports.download',
                    $export->expires_at,
                    ['export' => $export->getKey()],
                ),
                urgency: 'normal',
            ),
            Audience::user($recipient),
            $export,
        );
    }

    private function filename(CarbonInterface $asOf): string
    {
        return 'Daily-executive-report-'.$asOf->format('Y-m-d').'.pdf';
    }
}
