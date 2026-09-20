<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\KpiDefinition;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\ReportCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * The rules behind the report builder (§73, §84, docs/11 §1).
 *
 * THE WRITE-SIDE HALF OF THE WHITELIST, exactly as KpiDefinitionService is for KPIs. Every field a
 * definition names is looked up in ReportCatalogue here, when it is saved, and again in ReportRunner
 * when it is run. Neither check makes the other redundant: this one turns a bad definition into a
 * sentence on a form, and the second one protects a good definition whose register changed
 * underneath it two years later.
 *
 * THREE LISTS, NOT ONE, AND THE DIFFERENCE IS THE POINT. A column is checked against
 * `columnsFor()`, a filter against `filterFieldsFor()`, a grouping against `groupFieldsFor()`. It
 * would be less code to check everything against the widest of the three. It would also mean that
 * the moment somebody could PRINT a document title they could FILTER on it, which is a way to read
 * the titles of documents they cannot open, one guess at a time, with the row count as the oracle
 * (§61). The lists differ because the questions differ.
 *
 * `organization_id` IS NEVER READ FROM THE PAYLOAD (§74). It comes from the actor, and it is set
 * before the name check so that "is this name taken" asks about this company rather than about
 * every company on the server — which would leak the report names of every other tenant through a
 * validation message (§75).
 */
final class ReportDefinitionService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /** @param  array<string,mixed>  $attributes */
    public function create(array $attributes, User $actor): ReportDefinition
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $report = new ReportDefinition;

            // §74, and set first for the reason given in the class docblock.
            $report->organization_id = $actor->organization_id;
            $report->created_by = $actor->getKey();

            $this->fill($report, $attributes, $actor);
            $report->save();

            $this->activity->record(
                $report,
                'created',
                $actor->name.' built the report “'.$report->name.'”.',
                ['entity_type' => $report->entity_type, 'columns' => count($report->selectedColumns())],
                $actor,
            );

            return $report;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(ReportDefinition $report, array $attributes, User $actor): ReportDefinition
    {
        return DB::transaction(function () use ($report, $attributes, $actor) {
            $this->fill($report, $attributes, $actor);

            $changed = array_keys($report->getDirty());

            if ($changed === []) {
                return $report;
            }

            $report->save();

            // Named fields rather than a bare "updated", for the reason the KPI service gives: the
            // question asked of this trail is never "was it edited" but "did the question change
            // under the answer". A report circulated to a client last quarter and re-run this one
            // must be traceable to the filters it had each time.
            $this->activity->record(
                $report,
                'updated',
                $actor->name.' changed the report “'.$report->name.'”.',
                ['fields' => $changed],
                $actor,
            );

            return $report;
        });
    }

    public function delete(ReportDefinition $report, User $actor): void
    {
        $name = $report->name;

        $this->activity->record(
            $report,
            'deleted',
            $actor->name.' removed the report “'.$name.'”.',
            [],
            $actor,
        );

        $report->delete();
    }

    /**
     * Switch a report off without losing the question it asked.
     *
     * Preferred over deleting, and for a sharper reason than the KPI equivalent: a report that has
     * been sent to a client is a document somebody may have to reproduce. Deleting the definition
     * makes the figures in that document unaccountable. Switching it off takes it off the list and
     * leaves the question where an auditor can still read it.
     */
    public function setActive(ReportDefinition $report, bool $active, User $actor): ReportDefinition
    {
        if ($report->is_active === $active) {
            return $report;
        }

        $report->forceFill(['is_active' => $active])->save();

        $this->activity->record(
            $report,
            $active ? 'activated' : 'deactivated',
            $actor->name.($active ? ' switched on ' : ' switched off ').'the report “'.$report->name.'”.',
            [],
            $actor,
        );

        return $report;
    }

    // ================================================================== validation

    /** @param  array<string,mixed>  $attributes */
    private function fill(ReportDefinition $report, array $attributes, User $actor): void
    {
        $name = trim((string) ($attributes['name'] ?? $report->name ?? ''));

        if ($name === '') {
            throw new \DomainException('A report needs a name.');
        }

        if ($this->nameTaken($report, $name, $actor)) {
            throw new \DomainException(
                'There is already a report called “'.$name.'”. Two reports with one name is a set of '
                .'figures nobody can trace back to the question that produced them.'
            );
        }

        $entityType = (string) ($attributes['entity_type'] ?? $report->entity_type ?? '');

        if (! ReportCatalogue::listable($entityType)) {
            throw new \DomainException('That is not a register this platform can report on.');
        }

        $report->name = mb_substr($name, 0, 120);
        $report->description = blank($attributes['description'] ?? null)
            ? null : mb_substr(trim((string) $attributes['description']), 0, 2000);
        $report->entity_type = $entityType;

        $report->columns = $this->columns($entityType, $attributes['columns'] ?? []);
        $report->filters = $this->filters($entityType, $attributes['filters'] ?? []);
        $report->group_field = $this->groupField($entityType, $attributes);

        [$report->sort_field, $report->sort_direction] = $this->ordering($entityType, $attributes);

        $report->row_limit = $this->rowLimit($attributes, $report);
        $report->sort = (int) ($attributes['sort'] ?? $report->sort ?? 0);

        if (array_key_exists('is_active', $attributes)) {
            $report->is_active = (bool) $attributes['is_active'];
        } elseif ($report->is_active === null) {
            $report->is_active = true;
        }
    }

    /**
     * The columns printed, in the order given.
     *
     * ORDER IS PRESERVED AND DUPLICATES ARE DROPPED — a report is read left to right, so the
     * sequence the builder submitted is information rather than an accident of iteration.
     *
     * @param  mixed  $raw
     * @return array<int,string>
     */
    private function columns(string $entityType, $raw): array
    {
        $columns = [];

        foreach ((array) $raw as $column) {
            $column = is_string($column) ? trim($column) : '';

            if ($column === '') {
                continue;   // an untouched row from the builder, not an error
            }

            if (! ReportCatalogue::offersColumn($entityType, $column)) {
                throw new \DomainException('That register does not offer a “'.$column.'” column.');
            }

            if (! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        if ($columns === []) {
            throw new \DomainException('A report needs at least one column, or there is nothing to read.');
        }

        if (count($columns) > ReportDefinition::MAX_COLUMNS) {
            throw new \DomainException(
                'A report may print at most '.ReportDefinition::MAX_COLUMNS.' columns. Past that it is '
                .'a spreadsheet somebody exports and never opens.'
            );
        }

        return $columns;
    }

    /**
     * The stored conditions.
     *
     * Checked against the FILTER list and never the column list. The distinction is the one the
     * class docblock argues for, and this is the method where getting it wrong would be invisible.
     *
     * @param  mixed  $raw
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    private function filters(string $entityType, $raw): array
    {
        $filters = [];

        foreach ((array) $raw as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? '');

            if ($field === '' && $operator === '') {
                continue;   // a blank condition row on the form
            }

            if (! ReportCatalogue::offersFilter($entityType, $field)) {
                throw new \DomainException('That register cannot be filtered on “'.$field.'”.');
            }

            if (! array_key_exists($operator, KpiDefinition::FILTER_OPERATORS)) {
                throw new \DomainException('“'.$operator.'” is not a comparison this platform makes.');
            }

            $needsValue = ! in_array($operator, KpiDefinition::OPERATORS_WITHOUT_VALUE, true);
            $value = $filter['value'] ?? null;

            if ($needsValue && blank($value)) {
                throw new \DomainException('The condition on “'.$field.'” needs something to compare against.');
            }

            $filters[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $needsValue ? $this->value($value) : null,
            ];
        }

        if (count($filters) > ReportDefinition::MAX_FILTERS) {
            throw new \DomainException(
                'A report may carry at most '.ReportDefinition::MAX_FILTERS.' conditions. A question '
                .'that takes more than that to ask is two questions.'
            );
        }

        return $filters;
    }

    /** @param  array<string,mixed>  $attributes */
    private function groupField(string $entityType, array $attributes): ?string
    {
        $field = trim((string) ($attributes['group_field'] ?? ''));

        if ($field === '') {
            return null;
        }

        if (! ReportCatalogue::offersGroup($entityType, $field)) {
            throw new \DomainException('That register cannot be grouped by “'.$field.'”.');
        }

        return $field;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array{0:string|null,1:string}
     */
    private function ordering(string $entityType, array $attributes): array
    {
        $direction = (string) ($attributes['sort_direction'] ?? 'desc');
        $direction = array_key_exists($direction, ReportDefinition::DIRECTIONS) ? $direction : 'desc';

        $field = trim((string) ($attributes['sort_field'] ?? ''));

        if ($field === '') {
            return [null, $direction];
        }

        if (! ReportCatalogue::offersSort($entityType, $field)) {
            throw new \DomainException('That register cannot be sorted by “'.$field.'”.');
        }

        return [$field, $direction];
    }

    /** @param  array<string,mixed>  $attributes */
    private function rowLimit(array $attributes, ReportDefinition $report): int
    {
        if (! array_key_exists('row_limit', $attributes) || blank($attributes['row_limit'])) {
            return (int) ($report->row_limit ?: ReportDefinition::DEFAULT_ROW_LIMIT);
        }

        $limit = (int) $attributes['row_limit'];

        if ($limit < 1) {
            throw new \DomainException('A report has to return at least one row.');
        }

        if ($limit > ReportDefinition::MAX_ROWS) {
            // Refused rather than silently clamped. A company that asked for fifty thousand rows
            // and was quietly given five thousand would reconcile against the short list and find
            // the difference months later, with nothing on the page having said so.
            throw new \DomainException(
                'A report may return at most '.number_format(ReportDefinition::MAX_ROWS).' rows at a time. '
                .'Narrow it with a filter, or group it.'
            );
        }

        return $limit;
    }

    /** Filter values are compared against structured columns, so a scalar or a list of them. */
    private function value(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => is_scalar($item)));
        }

        return is_scalar($value) ? $value : null;
    }

    private function nameTaken(ReportDefinition $report, string $name, User $actor): bool
    {
        return ReportDefinition::query()
            ->where('organization_id', $actor->organization_id)
            ->where('name', $name)
            ->when($report->exists, fn ($query) => $query->whereKeyNot($report->getKey()))
            ->exists();
    }
}
