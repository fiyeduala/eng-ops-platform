<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\KpiDefinition;
use App\Models\User;
use App\Support\KpiCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * The rules behind the KPI builder (§56, §73, §84).
 *
 * THE WRITE-SIDE HALF OF THE WHITELIST. KpiCatalogue is asked twice about every field a definition
 * names: here, when somebody saves one, and again in KpiEvaluator when the number is counted. The
 * second check is what protects a definition that outlives the register it points at; this one is
 * what stops a bad definition being stored in the first place, so the failure is a sentence on a
 * form rather than a dashboard tile that silently reports nothing eighteen months later.
 *
 * WHAT ARRIVES FROM THE BROWSER AND WHAT DOES NOT. `entity_type`, every filter field, the measure
 * column and the date column are all select-box values, which is to say strings from the browser
 * that end up inside a query builder. Every one is looked up in a list the platform offers rather
 * than checked for plausibility, because "looks like a column name" is not a security property.
 * `organization_id` is never read from the payload at all — it comes from the actor (§74).
 *
 * A DEFINITION THAT CANNOT BE COUNTED IS NOT SAVED. An aggregate with no column to add up, a ratio
 * with no numerator, a window with no date: each is a KPI that would render as a permanent blank
 * tile with a reason nobody reads. Refusing them here is the difference between a company being
 * told what is wrong and a company slowly learning to ignore a dashboard.
 */
final class KpiDefinitionService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /** @param  array<string,mixed>  $attributes */
    public function create(array $attributes, User $actor): KpiDefinition
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $kpi = new KpiDefinition;

            // §74: from the actor, never from the payload, and set before anything else so that
            // the uniqueness check below asks about this company rather than about all of them.
            $kpi->organization_id = $actor->organization_id;

            $this->fill($kpi, $attributes, $actor);
            $kpi->save();

            $this->activity->record(
                $kpi,
                'created',
                $actor->name.' added the measure “'.$kpi->name.'”.',
                ['entity_type' => $kpi->entity_type, 'aggregate' => $kpi->aggregate],
                $actor,
            );

            return $kpi;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(KpiDefinition $kpi, array $attributes, User $actor): KpiDefinition
    {
        return DB::transaction(function () use ($kpi, $attributes, $actor) {
            $this->fill($kpi, $attributes, $actor);

            $changed = array_keys($kpi->getDirty());

            if ($changed === []) {
                return $kpi;
            }

            $kpi->save();

            // Named fields rather than a bare "updated", because the question asked of this trail
            // is never "was it edited" — it is "did the definition change under the number".
            // A board reading a figure called "overdue deliverables" is entitled to know that its
            // filters were widened between one quarter and the next.
            $this->activity->record(
                $kpi,
                'updated',
                $actor->name.' changed how “'.$kpi->name.'” is measured.',
                ['fields' => $changed],
                $actor,
            );

            return $kpi;
        });
    }

    public function delete(KpiDefinition $kpi, User $actor): void
    {
        $name = $kpi->name;

        $this->activity->record(
            $kpi,
            'deleted',
            $actor->name.' removed the measure “'.$name.'”.',
            [],
            $actor,
        );

        $kpi->delete();
    }

    /**
     * Switch a measure off without losing what it meant.
     *
     * Preferred over deleting, and offered separately for that reason: a KPI that has been quoted
     * in a board pack is the only record of how that figure was arrived at, and deleting it makes
     * the old number unreproducible. Switching it off takes it off the dashboard and leaves the
     * definition where somebody can still read it.
     */
    public function setActive(KpiDefinition $kpi, bool $active, User $actor): KpiDefinition
    {
        if ($kpi->is_active === $active) {
            return $kpi;
        }

        $kpi->forceFill(['is_active' => $active])->save();

        $this->activity->record(
            $kpi,
            $active ? 'activated' : 'deactivated',
            $actor->name.($active ? ' switched on ' : ' switched off ').'the measure “'.$kpi->name.'”.',
            [],
            $actor,
        );

        return $kpi;
    }

    // ================================================================== validation

    /** @param  array<string,mixed>  $attributes */
    private function fill(KpiDefinition $kpi, array $attributes, User $actor): void
    {
        $name = trim((string) ($attributes['name'] ?? $kpi->name ?? ''));

        if ($name === '') {
            throw new \DomainException('A measure needs a name.');
        }

        if ($this->nameTaken($kpi, $name, $actor)) {
            throw new \DomainException(
                'There is already a measure called “'.$name.'”. Two measures with one name is a '
                .'number nobody can settle an argument with.'
            );
        }

        $entityType = (string) ($attributes['entity_type'] ?? $kpi->entity_type ?? '');

        if (! KpiCatalogue::countable($entityType)) {
            throw new \DomainException('That is not a register this platform can count.');
        }

        $aggregate = (string) ($attributes['aggregate'] ?? $kpi->aggregate ?? 'count');

        if (! array_key_exists($aggregate, KpiDefinition::AGGREGATES)) {
            throw new \DomainException('That is not a calculation this platform does.');
        }

        $kpi->name = mb_substr($name, 0, 120);
        $kpi->description = blank($attributes['description'] ?? null)
            ? null : mb_substr(trim((string) $attributes['description']), 0, 2000);
        $kpi->entity_type = $entityType;
        $kpi->aggregate = $aggregate;

        $kpi->measure_field = $this->measureField($entityType, $aggregate, $attributes);
        $kpi->filters = $this->filters($entityType, $attributes['filters'] ?? []);
        $kpi->numerator_filters = in_array($aggregate, KpiDefinition::AGGREGATES_NEEDING_NUMERATOR, true)
            ? $this->numeratorFilters($entityType, $attributes['numerator_filters'] ?? [])
            : null;

        [$kpi->date_field, $kpi->period] = $this->window($entityType, $attributes);

        $kpi->scope = $this->scope($entityType, $attributes);
        $kpi->direction = array_key_exists((string) ($attributes['direction'] ?? ''), KpiDefinition::DIRECTIONS)
            ? (string) $attributes['direction']
            : ($kpi->direction ?? 'higher_better');

        $kpi->target = is_numeric($attributes['target'] ?? null) ? (float) $attributes['target'] : null;
        $kpi->unit = blank($attributes['unit'] ?? null) ? null : mb_substr(trim((string) $attributes['unit']), 0, 24);
        $kpi->thresholds = $this->thresholds($attributes['thresholds'] ?? null);
        $kpi->sort = (int) ($attributes['sort'] ?? $kpi->sort ?? 0);

        if (array_key_exists('is_active', $attributes)) {
            $kpi->is_active = (bool) $attributes['is_active'];
        } elseif ($kpi->is_active === null) {
            $kpi->is_active = true;
        }
    }

    /** @param  array<string,mixed>  $attributes */
    private function measureField(string $entityType, string $aggregate, array $attributes): ?string
    {
        if (! in_array($aggregate, KpiDefinition::AGGREGATES_NEEDING_MEASURE, true)) {
            // Cleared rather than kept. A count carrying a leftover measure column from when it was
            // a sum is a definition that reads as though it measures something it does not.
            return null;
        }

        $field = (string) ($attributes['measure_field'] ?? '');

        if (! KpiCatalogue::offersMeasure($entityType, $field)) {
            throw new \DomainException(
                'A total or an average needs a figure to work on, and that register does not offer that one.'
            );
        }

        return $field;
    }

    /**
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
                // An untouched blank row from the builder. Skipped rather than refused, because a
                // form with three empty condition rows on it is not an error.
                continue;
            }

            if (! KpiCatalogue::offersFilter($entityType, $field)) {
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

        if (count($filters) > KpiDefinition::MAX_FILTERS) {
            throw new \DomainException(
                'A measure may carry at most '.KpiDefinition::MAX_FILTERS.' conditions. A number that '
                .'takes more than that to describe is two numbers.'
            );
        }

        return $filters;
    }

    /**
     * @param  mixed  $raw
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    private function numeratorFilters(string $entityType, $raw): array
    {
        $filters = $this->filters($entityType, $raw);

        if ($filters === []) {
            throw new \DomainException(
                'A ratio needs to say which of the records counted are the ones being measured, '
                .'otherwise it is always one.'
            );
        }

        return $filters;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array{0:string|null,1:string}
     */
    private function window(string $entityType, array $attributes): array
    {
        $period = (string) ($attributes['period'] ?? 'all_time');

        if (! array_key_exists($period, KpiDefinition::PERIODS)) {
            throw new \DomainException('That is not a period this platform counts over.');
        }

        if ($period === 'all_time') {
            return [null, 'all_time'];
        }

        $field = (string) ($attributes['date_field'] ?? '');

        if (! KpiCatalogue::offersDate($entityType, $field)) {
            throw new \DomainException(
                'A measure counted over a period has to say which date it is counting by.'
            );
        }

        return [$field, $period];
    }

    /** @param  array<string,mixed>  $attributes */
    private function scope(string $entityType, array $attributes): string
    {
        $scope = (string) ($attributes['scope'] ?? 'organization');

        if (! array_key_exists($scope, KpiDefinition::SCOPES)) {
            throw new \DomainException('That is not an altitude this platform measures at.');
        }

        // Refused rather than allowed to sit there, because the failure is otherwise invisible: a
        // project-scoped KPI over a register with no project column renders as a blank tile on
        // every project page, with nothing to suggest the definition is at fault.
        $column = match ($scope) {
            'department' => KpiCatalogue::orgUnitColumnFor($entityType),
            'project' => KpiCatalogue::projectColumnFor($entityType),
            'employee' => KpiCatalogue::employeeColumnFor($entityType),
            default => 'organization_id',
        };

        if ($column === null) {
            throw new \DomainException('That register cannot be broken down that way.');
        }

        return $scope;
    }

    /**
     * @param  mixed  $raw
     * @return array<string,float>|null
     */
    private function thresholds($raw): ?array
    {
        $raw = (array) ($raw ?? []);
        $thresholds = [];

        foreach (['warning', 'critical'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                $thresholds[$key] = (float) $raw[$key];
            }
        }

        return $thresholds === [] ? null : $thresholds;
    }

    /** Filter values are compared against structured columns, so a scalar or a list of them. */
    private function value(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => is_scalar($item)));
        }

        return is_scalar($value) ? $value : null;
    }

    private function nameTaken(KpiDefinition $kpi, string $name, User $actor): bool
    {
        return KpiDefinition::query()
            ->where('organization_id', $actor->organization_id)
            ->where('name', $name)
            ->when($kpi->exists, fn ($query) => $query->whereKeyNot($kpi->getKey()))
            ->exists();
    }
}
