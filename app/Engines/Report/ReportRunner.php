<?php

declare(strict_types=1);

namespace App\Engines\Report;

use App\Engines\Query\FilterCompiler;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\RecordPresenter;
use App\Support\ReportCatalogue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A report, answered for one person.
 *
 * THE FIRST LINE OF `baseQuery()` IS THE WHOLE SECURITY MODEL, and it is deliberately identical to
 * KpiEvaluator's: `visibleTo($viewer, ...)` before any filter, any sort, any grouping. Everything
 * after it only ever removes rows. That ordering is what makes the rest of this class structurally
 * unable to widen an answer past its reader, rather than merely careful not to — and it is why
 * there is no method here that takes a query and adds an `orWhere`.
 *
 * THE CONSEQUENCE WORTH STATING PLAINLY: two people run the same report and correctly get different
 * rows, and different totals at the bottom. That is not a bug to be reconciled away. It is docs/11
 * §1 — "if a report and a list page disagree, that is a bug" — read in the only direction that is
 * safe, which is that the report agrees with each reader's own index page rather than with some
 * canonical version of itself.
 *
 * NOTHING IS CACHED AND NOTHING IS STORED. No result row, no total, no "last run". A cached report
 * belongs to whoever first ran it, and serving it to a second reader is the disclosure the whole
 * visibility engine exists to prevent (§61).
 */
class ReportRunner
{
    public function __construct(
        // The compiler KpiEvaluator uses. One implementation, so a report and the KPI beside it
        // cannot drift into narrowing the same register two different ways (docs/11 §1).
        private readonly FilterCompiler $filters,
    ) {}

    /**
     * Run one report.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     *                                                                                    ad-hoc conditions from the run screen, validated exactly as the stored ones are
     */
    public function run(
        ReportDefinition $report,
        ?User $viewer,
        array $extraFilters = [],
        ?CarbonInterface $ranAt = null,
    ): ReportResult {
        $viewerId = $viewer?->getKey();

        if ($viewer === null) {
            return ReportResult::unavailable('Nobody is signed in, so there is nothing to list.');
        }

        // §75, and not a formality. A report reached by id is a report reached by URL, and the
        // route binding proves only that the row exists. Without this, a definition belonging to
        // another company would be run here — the ROWS would still be this viewer's, because
        // `visibleTo()` is applied below, but the COLUMNS, FILTERS and NAME would be the other
        // company's, which is a disclosure of how a competitor measures itself. KpiEvaluator
        // carries the same guard; it was missing there once and is the hole Part 5 closed.
        if ((int) $report->organization_id !== (int) $viewer->organization_id) {
            return ReportResult::unavailable('This report belongs to another company.', $viewerId);
        }

        if (! $report->is_active) {
            return ReportResult::unavailable('This report has been switched off.', $viewerId);
        }

        $class = ReportCatalogue::modelFor($report->entity_type);

        if ($class === null || ! ReportCatalogue::listable($report->entity_type)) {
            return ReportResult::unavailable(
                'This report lists a register the platform no longer offers.', $viewerId,
            );
        }

        $columns = $this->columns($report);

        if ($columns === []) {
            return ReportResult::unavailable(
                'None of the columns this report prints are offered by that register any more.', $viewerId,
            );
        }

        $query = $this->baseQuery($report, $class, $viewer, $extraFilters);

        if ($query === null) {
            return ReportResult::unavailable(
                'This report filters on something that register no longer offers.', $viewerId,
            );
        }

        return $this->collect($report, $query, $columns, $viewerId, $ranAt ?? Carbon::now());
    }

    /**
     * How many rows this reader would get, without building any of them.
     *
     * ADDED FOR THE EXPORT THRESHOLD (Phase 8, docs/11 §3), and bounded on purpose. The question an
     * export asks is not "how many rows are there" but "is this more than N" — so the count stops at
     * N+1 and never scans the whole register. On a company with sixty thousand purchase orders the
     * difference between those two questions is the difference between a decision and a timeout,
     * which would be an absurd way to fail while deciding whether something might time out.
     *
     * IT IS THE SAME QUERY `run()` COUNTS, through the same `baseQuery()`, so a threshold decision
     * and the file it leads to can never be about different row sets. Null means the report cannot
     * be run at all — the caller then calls `run()` and gets the proper unavailable result with its
     * reason, rather than this method inventing a second wording for the same refusal.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     */
    public function countUpTo(
        ReportDefinition $report,
        ?User $viewer,
        int $ceiling,
        array $extraFilters = [],
    ): ?int {
        if ($viewer === null || (int) $report->organization_id !== (int) $viewer->organization_id) {
            return null;
        }

        if (! $report->is_active) {
            return null;
        }

        $class = ReportCatalogue::modelFor($report->entity_type);

        if ($class === null || ! ReportCatalogue::listable($report->entity_type)) {
            return null;
        }

        $query = $this->baseQuery($report, $class, $viewer, $extraFilters);

        if ($query === null) {
            return null;
        }

        $model = $query->getModel();

        // The ordering and the eager loads baseQuery() applied are work a count does not need.
        // Cleared rather than avoided, because the alternative is a second query builder beside the
        // one the export will actually run, which is how a threshold comes to be decided about a
        // different set of rows than the file contains.
        $inner = $query->setEagerLoads([])
            ->reorder()
            ->select($model->qualifyColumn($model->getKeyName()))
            ->limit(max(1, $ceiling) + 1)
            ->toBase();

        // COUNTED THROUGH A SUBQUERY, and this is the only way the ceiling actually applies.
        // `->limit(n)->count()` does NOT stop at n: the LIMIT lands on the aggregate's single result
        // row, the COUNT still scans everything, and the method would look bounded while doing the
        // exact full-register scan it exists to avoid.
        return (int) $model->getConnection()->query()->fromSub($inner, 'bounded')->count();
    }

    // ================================================================== the query

    /**
     * @param  class-string<Model>  $class
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     * @return Builder<Model>|null
     */
    private function baseQuery(
        ReportDefinition $report,
        string $class,
        User $viewer,
        array $extraFilters,
    ): ?Builder {
        // FIRST, BEFORE ANYTHING ELSE. See the class docblock: every narrowing below only removes
        // rows, so beginning here is what bounds the whole method.
        $query = $class::query()->visibleTo($viewer, $report->viewPermissionFor($class));

        if (! $this->filters->apply($query, $report->entity_type, $report->baseFilters())) {
            return null;
        }

        // Ad-hoc conditions go through the SAME compiler and the same whitelist as the stored ones.
        // They arrive from a form, so they are the less trustworthy of the two — which is an
        // argument for one code path rather than for a second, more careful one beside it (§84).
        if (! $this->filters->apply($query, $report->entity_type, $extraFilters)) {
            return null;
        }

        $this->eagerLoad($query, $report);
        $this->order($query, $report);

        return $query;
    }

    /**
     * Load the relations the printed columns will ask for.
     *
     * §89, and more than a courtesy here: the platform runs with lazy loading prevented outside
     * production, so a foreign key rendered as its record without this would not merely be slow —
     * it would throw, five hundred times, halfway down a report.
     *
     * @param  Builder<Model>  $query
     */
    private function eagerLoad(Builder $query, ReportDefinition $report): void
    {
        $relations = [];

        foreach ($report->selectedColumns() as $column) {
            $relation = ReportCatalogue::relationFor($report->entity_type, $column);

            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        if ($report->isGrouped()) {
            $relation = ReportCatalogue::relationFor($report->entity_type, (string) $report->group_field);

            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        if ($relations !== []) {
            $query->with(array_unique($relations));
        }
    }

    /**
     * Order the rows: by the grouping field first where there is one, then by the report's own sort.
     *
     * The group field leads because grouping is done in PHP over an ordered list rather than with a
     * second query per group — one query for a grouped report rather than one per distinct value,
     * which on a department grouping is the difference between one query and forty (§89).
     *
     * @param  Builder<Model>  $query
     */
    private function order(Builder $query, ReportDefinition $report): void
    {
        $model = $query->getModel();

        if ($report->isGrouped()) {
            $field = (string) $report->group_field;

            // Only a plain column can be ordered on directly. A dotted grouping field is sorted
            // after the fact, in `collect()`, because ordering across a relation needs a join and
            // a join would multiply the rows being listed.
            if (! str_contains($field, '.')) {
                $query->orderBy($model->qualifyColumn($field));
            }
        }

        $sortField = (string) ($report->sort_field ?? '');

        if ($sortField !== '' && ReportCatalogue::offersSort($report->entity_type, $sortField)) {
            $query->orderBy(
                $model->qualifyColumn($sortField),
                $report->sort_direction === 'asc' ? 'asc' : 'desc',
            );

            return;
        }

        // A report with no ordering is a report whose rows move between runs, which makes two
        // printouts of the same thing impossible to compare. Newest-first is the convention every
        // index page in the platform already uses.
        $query->orderBy($model->qualifyColumn($model->getKeyName()), 'desc');
    }

    // ================================================================== the rows

    /**
     * @param  Builder<Model>  $query
     * @param  array<string,string>  $columns
     */
    private function collect(
        ReportDefinition $report,
        Builder $query,
        array $columns,
        ?int $viewerId,
        CarbonInterface $ranAt,
    ): ReportResult {
        $ceiling = $report->rowCeiling();

        // One more than the ceiling, which is how the list finds out it was cut short without
        // paying for a `count()` over the whole set. The extra row is dropped; its existence is
        // the entire message.
        $records = $query->limit($ceiling + 1)->get();
        $truncated = $records->count() > $ceiling;

        if ($truncated) {
            $records = $records->slice(0, $ceiling);
        }

        $groups = [];
        $grouping = $report->isGrouped() ? (string) $report->group_field : null;

        foreach ($records as $record) {
            $label = $grouping === null ? null : $this->groupLabel($report, $record, $grouping);
            $key = $label ?? '';

            if (! isset($groups[$key])) {
                $groups[$key] = ['label' => $label, 'rows' => []];
            }

            $row = [];

            foreach (array_keys($columns) as $column) {
                $row[$column] = $this->cell($report, $record, $column);
            }

            $groups[$key]['rows'][] = $row;
        }

        return new ReportResult(
            columns: $columns,
            groups: array_values($groups),
            total: $records->count(),
            truncated: $truncated,
            ranFor: $viewerId,
            ranAt: $ranAt,
        );
    }

    /**
     * The headings, in print order, as the register still offers them.
     *
     * @return array<string,string>
     */
    private function columns(ReportDefinition $report): array
    {
        $offered = ReportCatalogue::columnsFor($report->entity_type);
        $columns = [];

        foreach ($report->selectedColumns() as $column) {
            $columns[$column] = $offered[$column] ?? Str::headline($column);
        }

        return $columns;
    }

    private function groupLabel(ReportDefinition $report, Model $record, string $field): string
    {
        $value = $this->cell($report, $record, $field);

        return $value === '' ? 'Not set' : $value;
    }

    /**
     * One cell, as a reader should see it.
     *
     * Delegated to RecordPresenter since Phase 8, when the drill-down list needed to print the
     * records behind a figure exactly as the report beside it prints them. Two formatters would
     * have drifted within a phase; this way "the report and the drill-down disagree" is not a
     * failure that can occur (docs/11 §1).
     */
    private function cell(ReportDefinition $report, Model $record, string $column): string
    {
        return RecordPresenter::cell($report->entity_type, $record, $column);
    }
}
