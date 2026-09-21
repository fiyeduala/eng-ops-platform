<?php

declare(strict_types=1);

namespace App\Engines\Dashboard;

use App\Engines\Access\ReachInspector;
use App\Engines\Access\ReachVerdict;
use App\Engines\Kpi\KpiEvaluator;
use App\Engines\Kpi\KpiResult;
use App\Engines\Report\ReportRunner;
use App\Models\DashboardWidget;
use App\Models\KpiDefinition;
use App\Models\KpiSnapshot;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\KpiCatalogue;
use App\Support\WidgetCatalogue;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills in one board for one person (§6, §51, §52, §53, §61, §95).
 *
 * ONE ARRANGEMENT, MANY BOARDS. A company arranges the executive board once. Twelve people open it
 * and get twelve boards: the tiles each of them may see, carrying the figures their own visibility
 * produces. Nothing is hidden with CSS and nothing is filtered in a template — a tile whose register
 * this person cannot read is never built, so it cannot appear in the page source, in a print view or
 * in a screenshot taken over somebody's shoulder (§6).
 *
 * THE PERMISSION COMES FROM THE REGISTER, NOT FROM THE TILE. `DashboardWidget::requiredPermission()`
 * asks the measure which register it counts and that register which permission it is read under. A
 * board cannot publish a figure under a looser permission than the records behind it, because there
 * is nowhere in the arrangement to say so (§84).
 *
 * KPI PERMISSIONS ARE DELIBERATELY NOT ASKED FOR. Somebody reading the executive board needs to be
 * allowed to see incidents; they do not need `kpi.view`, which is the permission to inspect and
 * administer what the company measures. Requiring it would mean granting the KPI builder to
 * everybody who looks at a dashboard, which is how a configuration permission turns into a default.
 *
 * THE STORED-FIGURE RULE, WHICH IS THE POINT OF THE CLASS. A tile may ASK for last night's reading.
 * Asking is not getting: `ReachInspector` is consulted first, and the snapshot is handed over only
 * when this viewer's reach contains the entire scope it was counted over. Everybody else falls back
 * to a live count through their own visibility, carrying a sentence that says the figure is theirs
 * rather than the company's. So the fast path is available exactly where it is safe, the slow path
 * is correct everywhere, and no reader is ever shown a number derived from rows they could not open.
 *
 * WHY THE FALLBACK IS A FALLBACK AND NOT A BLANK. A tile that vanished for the people whose reach
 * is narrow would teach half a company that the dashboard is unreliable, and a tile that showed them
 * the shared figure would leak. Showing their own number, labelled, is the only option that is both
 * honest and useful — and the label is what stops two colleagues comparing screens and concluding
 * the platform is broken.
 */
class WidgetComposer
{
    public function __construct(
        private readonly KpiEvaluator $evaluator,
        private readonly ReportRunner $reports,
        private readonly ReachInspector $reach,
    ) {}

    /**
     * Build every tile on a board that this person may see.
     *
     * @param  int|null  $scopeId  which department or project this board is about; null for the
     *                             executive and personal boards
     * @return array<int,RenderedWidget>
     */
    public function compose(
        string $board,
        ?User $viewer,
        ?int $scopeId = null,
        ?CarbonInterface $asOf = null,
    ): array {
        if ($viewer === null || ! WidgetCatalogue::isBoard($board)) {
            return [];
        }

        $boardPermission = WidgetCatalogue::permissionForBoard($board);

        // Checked here as well as on the route. A composer called from a console command, a mailable
        // or the daily report has no middleware in front of it, and the second check costs one
        // cached lookup (§6: never rely on one layer).
        if ($boardPermission === null || ! $viewer->hasPermission($boardPermission)) {
            return [];
        }

        $asOf = $asOf === null ? Carbon::now() : Carbon::parse($asOf);
        $scopeType = WidgetCatalogue::scopeForBoard($board);

        $widgets = DashboardWidget::query()
            ->visibleTo($viewer, $boardPermission)
            ->onBoard($board)
            ->active()
            ->ordered()
            // §89. Twenty tiles asking their own definition for its name is twenty queries before
            // a single figure has been counted.
            ->with(['kpiDefinition', 'reportDefinition'])
            ->get();

        $rendered = [];

        foreach ($widgets as $widget) {
            $tile = $this->render($widget, $viewer, $scopeType, $scopeId, $asOf);

            if ($tile !== null) {
                $rendered[] = $tile;
            }
        }

        return $rendered;
    }

    /**
     * One tile, or null where this person should not be shown it at all.
     *
     * Null and "unavailable" are different answers on purpose. Null means the tile does not exist
     * for this reader — they lack the register's permission, or its definition has been switched
     * off — and nothing is drawn. Unavailable means the tile exists and could not be filled in, and
     * says why, because a silently missing tile and a broken one look identical otherwise.
     */
    private function render(
        DashboardWidget $widget,
        User $viewer,
        ?string $scopeType,
        ?int $scopeId,
        CarbonInterface $asOf,
    ): ?RenderedWidget {
        $source = $widget->source();

        if ($source === null || ! $source->is_active) {
            return null;
        }

        $permission = $widget->requiredPermission();

        // No derivable permission means the register behind this tile is no longer offered. Drawing
        // it would mean drawing a figure under no permission at all, so it is not drawn (§84).
        if ($permission === null || ! $viewer->hasPermission($permission)) {
            return null;
        }

        return match ($widget->kind) {
            'kpi_tile' => $this->figure($widget, $source, $viewer, $permission, $scopeType, $scopeId, $asOf),
            'kpi_trend' => $this->trend($widget, $source, $viewer, $permission, $scopeType, $scopeId, $asOf),
            'report_table' => $this->table($widget, $source, $viewer, $asOf),
            default => null,
        };
    }

    // ================================================================== one figure

    private function figure(
        DashboardWidget $widget,
        KpiDefinition|ReportDefinition $source,
        User $viewer,
        string $permission,
        ?string $scopeType,
        ?int $scopeId,
        CarbonInterface $asOf,
    ): ?RenderedWidget {
        if (! $source instanceof KpiDefinition) {
            return null;
        }

        // A measure defined per project, placed on the executive board, is a category error the
        // builder refuses to save — but a measure can be re-scoped after a tile is placed, and a
        // project figure quietly rendered company-wide is a wrong number rather than a missing one.
        if ($scopeType !== null && $source->scope !== $scopeType) {
            return $this->blank($widget, 'This measure is about '.($source->scope === 'employee'
                ? 'one person'
                : 'one '.$source->scope).', so it does not belong on this board.');
        }

        if ($widget->prefersStored() && $scopeType !== null) {
            $shared = $this->sharedFigure($widget, $source, $viewer, $permission, $scopeType, $scopeId, $asOf);

            if ($shared !== null) {
                return $shared;
            }
        }

        $result = $this->evaluator->evaluate($source, $viewer, $asOf, $scopeId);

        return new RenderedWidget(
            widget: $widget,
            kind: 'kpi_tile',
            heading: $widget->heading(),
            width: (string) $widget->width,
            value: $result->display($source),
            standing: $result->standing($source),
            available: $result->available,
            reason: $result->reason,
            shared: false,
            footnote: $this->ownFigureNote($widget, $viewer, $permission, $scopeType, $scopeId),
            target: $this->targetNote($source),
            href: $this->drilldown($source, $scopeType, $scopeId),
        );
    }

    /**
     * Where this figure's records are listed (§89).
     *
     * THE SCOPE TRAVELS IN THE URL, which docs/05 §8 requires and which is the only part of this
     * that needs care: the id is re-proven against the reader's own visibility on arrival, so the
     * link carries context rather than authority. A tile on the Civil board links to Civil's
     * records; somebody who edits the id to Piping is refused there, not here.
     *
     * Null for the personal board. There is no scope to carry, and an unscoped link from a personal
     * tile would list the reader's own figure under a page that looks company-wide.
     */
    private function drilldown(KpiDefinition $kpi, ?string $scopeType, ?int $scopeId): ?string
    {
        if ($scopeType === null) {
            return null;
        }

        return route('drilldown.measure', array_filter([
            'kpiDefinition' => $kpi->getKey(),
            'scope' => $kpi->scope === 'organization' ? null : $scopeId,
        ]));
    }

    /**
     * Last night's reading, if this person is entitled to the whole scope it was counted over.
     *
     * Returns null rather than a tile wherever the shared figure cannot be served — no reach, no
     * reading taken yet — so that the caller falls through to a live count. Null here is "not this
     * way", never "not at all".
     */
    private function sharedFigure(
        DashboardWidget $widget,
        KpiDefinition $kpi,
        User $viewer,
        string $permission,
        string $scopeType,
        ?int $scopeId,
        CarbonInterface $asOf,
    ): ?RenderedWidget {
        if (! $this->verdict($viewer, $kpi, $permission, $scopeType, $scopeId)?->covers) {
            return null;
        }

        $point = $this->latestPoint($kpi, $scopeType, $scopeId, $asOf);

        if ($point === null || ! $point->isAvailable()) {
            return null;
        }

        // Rebuilt into a KpiResult so that the stored value is formatted and judged against its
        // thresholds by exactly the same code that formats and judges a live one. A snapshot that
        // printed its decimals differently from the live figure beside it would be read as a
        // second, disagreeing number.
        $result = new KpiResult(
            value: (float) $point->value,
            available: true,
        );

        return new RenderedWidget(
            widget: $widget,
            kind: 'kpi_tile',
            heading: $widget->heading(),
            width: (string) $widget->width,
            value: $result->display($kpi),
            standing: $result->standing($kpi),
            shared: true,
            footnote: 'The whole '.$this->scopeWord($scopeType).', as read on '
                .$point->computed_at?->format('j M').'.',
            target: $this->targetNote($kpi),
            // Worth naming: a stored tile links to a LIVE list, so the figure above and the rows
            // below can legitimately differ by a day's work. The footnote already says when the
            // figure was read, which is what makes that difference legible rather than alarming —
            // the alternative, storing the row ids behind a snapshot, would be storing one person's
            // records for another person to open (§61).
            href: $this->drilldown($kpi, $scopeType, $scopeId),
        );
    }

    // ================================================================== a line

    private function trend(
        DashboardWidget $widget,
        KpiDefinition|ReportDefinition $source,
        User $viewer,
        string $permission,
        ?string $scopeType,
        ?int $scopeId,
        CarbonInterface $asOf,
    ): ?RenderedWidget {
        if (! $source instanceof KpiDefinition) {
            return null;
        }

        // The personal board has no scope wide enough to contain a stored figure, so it can carry
        // no trend. Nothing is drawn rather than a line counted afresh each night, which would
        // rewrite its own history every time an old record was edited (docs/11 §5).
        if ($scopeType === null) {
            return $this->blank($widget, 'A trend is about a whole company, department or project, so it is not shown here.');
        }

        if ($source->scope !== $scopeType) {
            return $this->blank($widget, 'This measure is about one '.$source->scope.', so it does not belong on this board.');
        }

        $verdict = $this->verdict($viewer, $source, $permission, $scopeType, $scopeId);

        // THE ONE PLACE WITH NO FALLBACK. A figure can be recounted for one person; a history
        // cannot, because the records it was drawn from have moved since. So somebody whose reach
        // falls short of the scope is told that plainly rather than shown a line that is partly
        // theirs and partly the company's.
        if ($verdict === null || ! $verdict->covers) {
            return $this->blank($widget, $verdict?->reason
                ?? 'This line is about a scope wider than the records you can see.');
        }

        $points = $this->points($source, $scopeType, $scopeId, $widget->span);

        return new RenderedWidget(
            widget: $widget,
            kind: 'kpi_trend',
            heading: $widget->heading(),
            width: (string) $widget->width,
            value: $this->lastValue($points, $source),
            available: $points !== [],
            reason: $points === [] ? 'No readings have been taken yet. The first is due overnight.' : null,
            shared: true,
            footnote: 'The whole '.$this->scopeWord($scopeType).', read nightly.',
            target: $this->targetNote($source),
            points: $points,
        );
    }

    /**
     * The stored series, oldest point first.
     *
     * Fetched newest-first with a limit and then reversed, because "the last twelve months" is a
     * question about the end of the line and ordering the whole history to take its tail is how a
     * five-year-old measure makes a dashboard slow (§89).
     *
     * @return array<int,array{label:string,value:float|null}>
     */
    private function points(KpiDefinition $kpi, string $scopeType, ?int $scopeId, int $span): array
    {
        $span = max(2, min($span, WidgetCatalogue::MAX_SPAN));

        $rows = KpiSnapshot::query()
            ->forDefinition($kpi)
            ->atScope($scopeType, $scopeId)
            ->orderByDesc('period_key')
            ->limit($span)
            ->get()
            ->reverse()
            ->values();

        $points = [];

        foreach ($rows as $row) {
            // A gap is a point with no value rather than a missing point, so that a night the sweep
            // did not run shows as a break in the line instead of two distant readings joined into
            // a straight one nobody knows is imaginary.
            $points[] = [
                'label' => (string) $row->period_key,
                'value' => $row->value === null ? null : (float) $row->value,
            ];
        }

        return $points;
    }

    /** @param  array<int,array{label:string,value:float|null}>  $points */
    private function lastValue(array $points, KpiDefinition $kpi): string
    {
        for ($i = count($points) - 1; $i >= 0; $i--) {
            if ($points[$i]['value'] !== null) {
                return (new KpiResult(value: $points[$i]['value']))->display($kpi);
            }
        }

        return '—';
    }

    // ================================================================== the top of a report

    /**
     * A few rows of a saved report, counted through whoever is looking.
     *
     * ALWAYS LIVE, never stored, and not for performance reasons. A snapshot of a FIGURE is one
     * number about a scope; a snapshot of ROWS is the records themselves, and there is no reach
     * test that makes one person's list safe to hand to another. ReportRunner already scopes to the
     * viewer, so this tile is exactly as safe as the report page it links to (§61).
     */
    private function table(
        DashboardWidget $widget,
        KpiDefinition|ReportDefinition $source,
        User $viewer,
        CarbonInterface $asOf,
    ): ?RenderedWidget {
        if (! $source instanceof ReportDefinition) {
            return null;
        }

        $result = $this->reports->run($source, $viewer, [], $asOf);

        if (! $result->available) {
            return $this->blank($widget, $result->reason ?? 'This report could not be run.');
        }

        $limit = max(1, min((int) $widget->row_limit, WidgetCatalogue::MAX_ROW_LIMIT));
        $rows = array_slice($result->rows(), 0, $limit);

        return new RenderedWidget(
            widget: $widget,
            kind: 'report_table',
            heading: $widget->heading(),
            width: (string) $widget->width,
            value: (string) $result->total,
            available: true,
            shared: false,
            footnote: $result->total > count($rows)
                ? 'The first '.count($rows).' of '.$result->total.' you can see.'
                : null,
            columns: $result->columns,
            rows: $rows,
            href: $viewer->hasPermission('report.view')
                ? route('configuration.reports.show', $source)
                : null,
        );
    }

    // ================================================================== shared helpers

    /**
     * Ask ReachInspector the question this board's altitude implies.
     *
     * Null where the question cannot be asked — a department board with no department, a register
     * the platform no longer offers — and a null verdict is read as "not covered" everywhere it is
     * used, so an unanswerable question never resolves in the reader's favour.
     */
    private function verdict(
        User $viewer,
        KpiDefinition $kpi,
        string $permission,
        string $scopeType,
        ?int $scopeId,
    ): ?ReachVerdict {
        $class = KpiCatalogue::modelFor((string) $kpi->entity_type);

        if ($class === null) {
            return null;
        }

        /** @var Model $prototype */
        $prototype = new $class;

        return match ($scopeType) {
            'organization' => $this->reach->coversOrganization($viewer, $prototype, $permission),
            'department' => $scopeId === null
                ? null
                : $this->reach->coversOrgUnit($viewer, $prototype, $permission, $scopeId),
            'project' => $scopeId === null
                ? null
                : $this->reach->coversProject($viewer, $prototype, $permission, $scopeId),
            default => null,
        };
    }

    /**
     * The sentence under a live figure, where there is one to say.
     *
     * Only printed where a shared figure was possible in principle and this person did not qualify
     * for it — that is, where somebody else looking at the same tile may well see a bigger number.
     * A board where every tile is live for everybody stays quiet, because a footnote on every tile
     * is a footnote nobody reads.
     */
    private function ownFigureNote(
        DashboardWidget $widget,
        User $viewer,
        string $permission,
        ?string $scopeType,
        ?int $scopeId,
    ): ?string {
        if (! $widget->prefersStored() || $scopeType === null) {
            return null;
        }

        $source = $widget->source();

        if (! $source instanceof KpiDefinition) {
            return null;
        }

        $verdict = $this->verdict($viewer, $source, $permission, $scopeType, $scopeId);

        if ($verdict === null) {
            return null;
        }

        return $verdict->covers
            // Covered, but there was no reading to serve — the sweep has not run for this measure
            // yet. Said plainly, because "counted for you" would be wrong and silence would leave
            // somebody wondering why a colleague's identical tile carries a date and theirs does not.
            ? 'No reading has been taken yet, so this is counted now.'
            : $verdict->reason;
    }

    private function latestPoint(
        KpiDefinition $kpi,
        string $scopeType,
        ?int $scopeId,
        CarbonInterface $asOf,
    ): ?KpiSnapshot {
        // At or before the period being asked about, newest first. Not "today's point": a board
        // opened at half past midnight, or the morning after a cron the host skipped, should show
        // yesterday's reading with yesterday's date on it rather than falling back to a live count
        // and silently losing the shared figure for everybody.
        return KpiSnapshot::query()
            ->forDefinition($kpi)
            ->atScope($scopeType, $scopeId)
            ->where('period_key', '<=', $kpi->periodKeyFor($asOf))
            ->orderByDesc('period_key')
            ->first();
    }

    private function targetNote(KpiDefinition $kpi): ?string
    {
        if ($kpi->target === null) {
            return null;
        }

        $target = (new KpiResult(value: (float) $kpi->target))->display($kpi);

        return ($kpi->higherIsBetter() ? 'Target at least ' : 'Target at most ').$target;
    }

    private function scopeWord(string $scopeType): string
    {
        return match ($scopeType) {
            'organization' => 'company',
            'department' => 'department',
            'project' => 'project',
            default => 'scope',
        };
    }

    private function blank(DashboardWidget $widget, string $reason): RenderedWidget
    {
        return new RenderedWidget(
            widget: $widget,
            kind: (string) $widget->kind,
            heading: $widget->heading(),
            width: (string) $widget->width,
            available: false,
            reason: $reason,
        );
    }
}
