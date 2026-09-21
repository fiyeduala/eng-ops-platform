<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\DashboardWidget;
use App\Models\KpiDefinition;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\WidgetCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules behind arranging a board (§73, §74, §84).
 *
 * EVERY ID ON THIS FORM IS A FOREIGN KEY ARRIVING FROM A BROWSER, which is the whole reason this
 * class exists rather than a controller calling `create()`. A measure id and a report id both look
 * like integers and both pass `exists`; what neither proves is that the row belongs to the company
 * arranging the board. Binding a rival's measure to this company's executive board would not leak
 * their records — the figure is counted through this viewer's visibility, so the ROWS would be
 * Meridian's — but it would publish the rival's definition of "overdue", under the rival's wording,
 * to a board the rival cannot see. So the source is re-fetched inside the actor's tenant and a
 * mismatch is refused (§74, §94).
 *
 * THE PERMISSION IS NEVER ACCEPTED FROM THE FORM. There is no field for it and no column to hold
 * it; WidgetComposer derives it from the source at render time. A form that offered it would let
 * somebody arrange a tile counting a register under a permission most people hold, and the tile
 * would be correct in every other respect (§6, §84).
 *
 * A TILE THAT CANNOT BE FILLED IN IS NOT SAVED. A trend on the personal board, a project measure on
 * the executive board, a kind whose source is empty: each renders as a permanent apologetic blank,
 * and a board carrying two of them is a board people stop reading. Refusing them here turns the
 * problem into a sentence on a form.
 */
final class DashboardWidgetService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /** @param  array<string,mixed>  $attributes */
    public function create(array $attributes, User $actor): DashboardWidget
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $widget = new DashboardWidget;

            // §74: from the actor, never from the payload, and set first so that the source lookup
            // below asks about this company rather than about all of them.
            $widget->organization_id = $actor->organization_id;

            $this->fill($widget, $attributes, $actor);

            if ($widget->sort === null || (int) $widget->sort === 0) {
                $widget->sort = WidgetCatalogue::nextSortFor(
                    (int) $actor->organization_id,
                    (string) $widget->board,
                );
            }

            $this->refuseOverfullBoard($widget, $actor);

            $widget->save();

            $this->activity->record(
                $widget,
                'created',
                $actor->name.' added “'.$widget->heading().'” to the '
                    .$this->boardLabel((string) $widget->board).'.',
                ['board' => $widget->board, 'kind' => $widget->kind],
                $actor,
            );

            return $widget;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(DashboardWidget $widget, array $attributes, User $actor): DashboardWidget
    {
        return DB::transaction(function () use ($widget, $attributes, $actor) {
            $this->fill($widget, $attributes, $actor);

            $changed = array_keys($widget->getDirty());

            if ($changed === []) {
                return $widget;
            }

            $widget->save();

            $this->activity->record(
                $widget,
                'updated',
                $actor->name.' changed “'.$widget->heading().'” on the '
                    .$this->boardLabel((string) $widget->board).'.',
                ['fields' => $changed],
                $actor,
            );

            return $widget;
        });
    }

    public function delete(DashboardWidget $widget, User $actor): void
    {
        $heading = $widget->heading();
        $board = $this->boardLabel((string) $widget->board);

        $this->activity->record(
            $widget,
            'deleted',
            $actor->name.' took “'.$heading.'” off the '.$board.'.',
            [],
            $actor,
        );

        $widget->delete();
    }

    /**
     * Take a tile off a board without losing how it was set up.
     *
     * Offered separately from deleting for the same reason a measure can be switched off: a board
     * is rearranged live, in the ten minutes before a meeting, by somebody who will want it back
     * afterwards.
     */
    public function setActive(DashboardWidget $widget, bool $active, User $actor): DashboardWidget
    {
        if ($widget->is_active === $active) {
            return $widget;
        }

        $widget->is_active = $active;
        $widget->save();

        $this->activity->record(
            $widget,
            $active ? 'activated' : 'deactivated',
            $actor->name.($active ? ' put “' : ' hid “').$widget->heading()
                .($active ? '” back on the ' : '” on the ').$this->boardLabel((string) $widget->board).'.',
            [],
            $actor,
        );

        return $widget;
    }

    /**
     * Put a board in the order somebody dragged it into.
     *
     * Tiles not on this board are ignored rather than rejected, because a reorder posted from a
     * stale page should rearrange what it can and leave the rest alone — not fail wholesale in
     * front of somebody who is about to present from the screen. Ids from another company are
     * filtered by the tenant scope on the query, not trusted from the list (§94).
     *
     * @param  array<int,int|string>  $orderedIds
     */
    public function reorder(string $board, array $orderedIds, User $actor): int
    {
        if (! WidgetCatalogue::isBoard($board)) {
            return 0;
        }

        $ids = array_values(array_unique(array_map('intval', $orderedIds)));

        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($board, $ids, $actor) {
            $widgets = DashboardWidget::query()
                ->onBoard($board)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            $moved = 0;
            $position = 0;

            foreach ($ids as $id) {
                $widget = $widgets->get($id);

                if ($widget === null) {
                    continue;
                }

                $position++;

                if ((int) $widget->sort === $position) {
                    continue;
                }

                $widget->sort = $position;
                $widget->saveQuietly();
                $moved++;
            }

            if ($moved > 0) {
                // Recorded against the board rather than tile by tile. "Somebody rearranged the
                // executive board" is the fact worth keeping; eleven rows saying a tile moved from
                // position four to position five is the fact that buries it.
                $this->activity->record(
                    $widgets->first(),
                    'reordered',
                    $actor->name.' rearranged the '.$this->boardLabel($board).'.',
                    ['moved' => $moved],
                    $actor,
                );
            }

            return $moved;
        });
    }

    // ================================================================== the rules

    /** @param  array<string,mixed>  $attributes */
    private function fill(DashboardWidget $widget, array $attributes, User $actor): void
    {
        $board = (string) ($attributes['board'] ?? $widget->board ?? '');
        $kind = (string) ($attributes['kind'] ?? $widget->kind ?? '');

        if (! WidgetCatalogue::isBoard($board)) {
            throw ValidationException::withMessages([
                'board' => 'Choose a board for this tile.',
            ]);
        }

        if (! WidgetCatalogue::isKind($kind)) {
            throw ValidationException::withMessages([
                'kind' => 'Choose what kind of tile this is.',
            ]);
        }

        // A trend needs a scope to be a trend ABOUT. The personal board has none — one person is
        // not a scope any second reader's reach can contain — so the combination is refused here
        // rather than saved and rendered as an apology.
        if (WidgetCatalogue::alwaysStored($kind) && WidgetCatalogue::scopeForBoard($board) === null) {
            throw ValidationException::withMessages([
                'kind' => 'A trend is about a whole company, department or project, so it cannot go on the landing page.',
            ]);
        }

        $widget->board = $board;
        $widget->kind = $kind;

        $this->bindSource($widget, $attributes, $actor, $board, $kind);

        $title = trim((string) ($attributes['title'] ?? ''));
        $widget->title = $title === '' ? null : mb_substr($title, 0, 120);

        $refresh = (string) ($attributes['refresh'] ?? $widget->refresh ?? 'live');
        $widget->refresh = array_key_exists($refresh, WidgetCatalogue::REFRESH) ? $refresh : 'live';

        $width = (string) ($attributes['width'] ?? $widget->width ?? 'third');
        $widget->width = array_key_exists($width, WidgetCatalogue::WIDTHS) ? $width : 'third';

        $widget->row_limit = $this->bounded(
            $attributes['row_limit'] ?? $widget->row_limit ?? 5, 1, WidgetCatalogue::MAX_ROW_LIMIT, 5,
        );

        $widget->span = $this->bounded(
            $attributes['span'] ?? $widget->span ?? 12, 2, WidgetCatalogue::MAX_SPAN, 12,
        );

        if (array_key_exists('sort', $attributes)) {
            $widget->sort = max(0, (int) $attributes['sort']);
        }

        if (array_key_exists('is_active', $attributes)) {
            $widget->is_active = (bool) $attributes['is_active'];
        }
    }

    /**
     * Attach the measure or report this tile draws from, and nothing else.
     *
     * THE COLUMN NOT USED BY THIS KIND IS CLEARED, not left standing. A tile switched from a trend
     * to a report table while keeping its old `kpi_definition_id` is a row whose meaning depends on
     * reading two columns in the right order, and the first piece of code that reads them in the
     * wrong order draws a figure from a measure the tile no longer claims to show.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function bindSource(
        DashboardWidget $widget,
        array $attributes,
        User $actor,
        string $board,
        string $kind,
    ): void {
        $column = WidgetCatalogue::sourceColumnFor($kind);
        $id = (int) ($attributes[$column] ?? $widget->{$column} ?? 0);

        if ($id <= 0) {
            throw ValidationException::withMessages([
                $column => WidgetCatalogue::usesKpi($kind)
                    ? 'Choose which measure this tile shows.'
                    : 'Choose which report this tile shows.',
            ]);
        }

        if (WidgetCatalogue::usesKpi($kind)) {
            // Re-fetched inside the actor's tenant. The id proves the row exists; this proves it is
            // this company's (§94). `withoutGlobalScopes` is deliberately NOT used.
            $kpi = KpiDefinition::query()->whereKey($id)->first();

            if ($kpi === null || (int) $kpi->organization_id !== (int) $actor->organization_id) {
                throw ValidationException::withMessages([
                    $column => 'That measure is not one of this company’s.',
                ]);
            }

            $boardScope = WidgetCatalogue::scopeForBoard($board);

            // A measure is a statement of altitude (KpiDefinition::SCOPES). Putting a project
            // measure on the executive board does not fail — it silently answers a different
            // question under the board's heading, which is worse.
            if ($boardScope !== null && $kpi->scope !== $boardScope) {
                throw ValidationException::withMessages([
                    $column => '“'.$kpi->name.'” is about one '.$kpi->scope
                        .', so it does not belong on the '.$this->boardLabel($board).'.',
                ]);
            }

            if ($boardScope === null && $kpi->scope !== 'employee' && $kpi->scope !== 'organization') {
                throw ValidationException::withMessages([
                    $column => '“'.$kpi->name.'” is about one '.$kpi->scope
                        .', and the landing page is not about any one of them.',
                ]);
            }

            $widget->kpi_definition_id = $kpi->getKey();
            $widget->report_definition_id = null;

            return;
        }

        $report = ReportDefinition::query()->whereKey($id)->first();

        if ($report === null || (int) $report->organization_id !== (int) $actor->organization_id) {
            throw ValidationException::withMessages([
                $column => 'That report is not one of this company’s.',
            ]);
        }

        $widget->report_definition_id = $report->getKey();
        $widget->kpi_definition_id = null;
    }

    /**
     * A board has a ceiling, and it is a comprehensibility one rather than a performance one.
     *
     * Twenty-four tiles is already more than anybody reads in a meeting. The limit matters more
     * because each tile is a query: a board nobody capped is how §95 turns into a page that takes
     * eleven seconds for the one person whose reach is narrow enough to need live counts.
     */
    private function refuseOverfullBoard(DashboardWidget $widget, User $actor): void
    {
        $existing = DashboardWidget::query()
            ->where('organization_id', $actor->organization_id)
            ->where('board', $widget->board)
            ->count();

        if ($existing >= WidgetCatalogue::MAX_PER_BOARD) {
            throw ValidationException::withMessages([
                'board' => 'The '.$this->boardLabel((string) $widget->board).' already holds '
                    .WidgetCatalogue::MAX_PER_BOARD.' tiles. Take one off before adding another.',
            ]);
        }
    }

    private function bounded(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    private function boardLabel(string $board): string
    {
        return strtolower(WidgetCatalogue::BOARDS[$board]['label'] ?? 'board');
    }
}
