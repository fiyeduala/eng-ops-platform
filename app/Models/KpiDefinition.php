<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One number a company decided to watch.
 *
 * A DEFINITION, NOT A FIGURE. Nothing here is a total. The row says which register to count, under
 * which conditions, over which window — and KpiEvaluator answers it afresh for whoever is asking,
 * through that person's own visibility. Two people open the same KPI and correctly see two
 * different numbers; a stored total could only have been one of them, and would have been a way to
 * read a count of records somebody was never allowed to see (§61).
 *
 * EVERY FIELD NAME IS VALIDATED TWICE. Once when the definition is saved and again when it is
 * evaluated, both times against KpiCatalogue rather than against the schema. The second check is
 * the one that matters: a definition is long-lived configuration and the register it names can be
 * changed underneath it, so a KPI pointing at a column that is no longer offered returns nothing
 * rather than reaching a query builder with a string somebody typed in 2026 (§84).
 */
class KpiDefinition extends Model
{
    use Auditable, BelongsToOrganization, Scopeable;

    /**
     * How a KPI turns rows into a number.
     *
     * `ratio` and `percent` are the same arithmetic shown two ways, and both exist because a
     * company writing "on-time completion" means a percentage while one writing "near-miss to
     * incident" means a ratio, and asking either to divide by hand is asking for the figure nobody
     * can reproduce.
     *
     * @var array<string,string>
     */
    public const AGGREGATES = [
        'count' => 'How many',
        'sum' => 'Total of',
        'avg' => 'Average of',
        'ratio' => 'Ratio',
        'percent' => 'Percentage',
    ];

    /** Aggregates that need a numeric column to work on. */
    public const AGGREGATES_NEEDING_MEASURE = ['sum', 'avg'];

    /** Aggregates that need a second, narrower filter set for the numerator. */
    public const AGGREGATES_NEEDING_NUMERATOR = ['ratio', 'percent'];

    /**
     * The window a KPI is counted over.
     *
     * Computed from the date the KPI is ASKED for, never stored. That is what lets one definition
     * answer "this month" and "last March" without a company defining the same KPI thirteen times.
     *
     * @var array<string,string>
     */
    public const PERIODS = [
        'all_time' => 'All time',
        'day' => 'That day',
        'week' => 'That week',
        'month' => 'That month',
        'quarter' => 'That quarter',
        'year' => 'That year',
    ];

    /**
     * What one instance of this number is about.
     *
     * Not a permission and not a filter — a statement of altitude. A department-scoped KPI put on a
     * project page is a category error, and saying so here is what lets a dashboard pick the right
     * tile instead of showing a company-wide incident count on every project.
     *
     * @var array<string,string>
     */
    public const SCOPES = [
        'organization' => 'The whole company',
        'department' => 'One department',
        'project' => 'One project',
        'employee' => 'One person',
    ];

    /** @var array<string,string> */
    public const DIRECTIONS = [
        'higher_better' => 'Higher is better',
        'lower_better' => 'Lower is better',
    ];

    /**
     * What a filter may ask.
     *
     * Deliberately NARROWER than the workflow builder's operator list, which this is otherwise
     * modelled on. `changed` is missing because a query has no previous value to compare against —
     * it is a question about an event, not about a row. `contains` is missing because it is the one
     * operator that only makes sense on free text, and the fields offered here are structured by
     * construction; leaving it in would be an invitation to widen the field list later to suit it.
     *
     * @var array<string,string>
     */
    public const FILTER_OPERATORS = [
        '=' => 'is',
        '!=' => 'is not',
        '>' => 'is greater than',
        '>=' => 'is at least',
        '<' => 'is less than',
        '<=' => 'is at most',
        'in' => 'is one of',
        'not_in' => 'is not one of',
        'between' => 'is between',
        'is_null' => 'is empty',
        'not_null' => 'is not empty',
    ];

    /** Operators whose value input the builder hides, because they take none. */
    public const OPERATORS_WITHOUT_VALUE = ['is_null', 'not_null'];

    /**
     * The most conditions one KPI may carry, per filter set.
     *
     * Not a performance limit — a comprehensibility one. A number nobody can restate in a sentence
     * is a number nobody trusts, and a KPI needing a dozen conditions is two KPIs.
     */
    public const MAX_FILTERS = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'numerator_filters' => 'array',
            'thresholds' => 'array',
            'target' => 'decimal:4',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ================================================================== scopes

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /** @param  Builder<static>  $query */
    public function scopeAtScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    // ================================================================== access hooks

    /**
     * A KPI definition belongs to the company, not to a department or a job.
     *
     * Left unreset, Scopeable's defaults would have the compiler emit SQL naming `org_unit_id` and
     * `project_id`, which this table does not have — a 500 on the configuration screen for exactly
     * the people whose scope is narrower than the whole company.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /**
     * Reading the list of KPIs reaches every row inside the tenant.
     *
     * A KPI definition is company-level reference data with no unit and no project, so a narrower
     * scope cannot select a subset of it — it selects nothing, and a department head holding
     * `kpi.view` would find the dashboard configuration empty. Only the read ability is listed: a
     * KPI is edited by somebody administering the company's measures, and "manage KPIs in my
     * department" quietly becoming "manage every KPI" is the escalation Scopeable warns about.
     *
     * @return array<int,string>
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['kpi.view'];
    }

    public static function permissionModule(): string
    {
        return 'kpi';
    }

    // ================================================================== reading

    /**
     * The conditions every counted record must meet.
     *
     * Cleaned on the way OUT rather than trusted from the column, for the same reason ExpiryWatch
     * cleans its milestones: a definition written by an earlier validator, a seed or a support
     * call should behave rather than reach the query builder with a malformed condition.
     *
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    public function baseFilters(): array
    {
        return $this->cleanFilters($this->filters);
    }

    /** @return array<int,array{field:string,operator:string,value:mixed}> */
    public function numeratorFilters(): array
    {
        if (! $this->needsNumerator()) {
            return [];
        }

        return $this->cleanFilters($this->numerator_filters);
    }

    /**
     * @param  mixed  $filters
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    private function cleanFilters($filters): array
    {
        $clean = [];

        foreach ((array) ($filters ?? []) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $field = isset($filter['field']) ? (string) $filter['field'] : '';
            $operator = isset($filter['operator']) ? (string) $filter['operator'] : '';

            if ($field === '' || ! array_key_exists($operator, self::FILTER_OPERATORS)) {
                continue;
            }

            $clean[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $filter['value'] ?? null,
            ];
        }

        return array_slice($clean, 0, self::MAX_FILTERS);
    }

    public function needsMeasure(): bool
    {
        return in_array($this->aggregate, self::AGGREGATES_NEEDING_MEASURE, true);
    }

    public function needsNumerator(): bool
    {
        return in_array($this->aggregate, self::AGGREGATES_NEEDING_NUMERATOR, true);
    }

    public function isWindowed(): bool
    {
        return $this->period !== 'all_time' && filled($this->date_field);
    }

    public function higherIsBetter(): bool
    {
        return $this->direction !== 'lower_better';
    }

    /** The permission a total is counted under. Never anything wider than reading the register. */
    public function viewPermissionFor(string $class): string
    {
        return method_exists($class, 'defaultViewPermission')
            ? $class::defaultViewPermission()
            : $this->entity_type.'.view';
    }

    /**
     * Where the tile turns amber, and where it turns red.
     *
     * Read through `direction` by whoever draws it, not here. Two numbers mean opposite things on
     * an incident KPI and a throughput one, and the model's job is to hand over what the company
     * said rather than to decide what it meant.
     *
     * @return array{warning:float|null,critical:float|null}
     */
    public function thresholdValues(): array
    {
        $thresholds = (array) ($this->thresholds ?? []);

        return [
            'warning' => isset($thresholds['warning']) && is_numeric($thresholds['warning'])
                ? (float) $thresholds['warning'] : null,
            'critical' => isset($thresholds['critical']) && is_numeric($thresholds['critical'])
                ? (float) $thresholds['critical'] : null,
        ];
    }

    public function displayName(): string
    {
        return $this->name;
    }
}
