<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\ReportCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question a company decided to keep asking.
 *
 * A DEFINITION, NOT A RESULT — the same distinction KpiDefinition draws, for the same reason and
 * with more at stake. The row says which register to list, which columns to print, under which
 * conditions and in which order. It never holds rows. ReportRunner answers it afresh for whoever
 * opened it, through that person's own visibility, so two people running one report correctly get
 * two different row counts. A stored result could only ever have been one person's, and handing it
 * to the other is the whole of what §61 forbids.
 *
 * THE COLUMN LIST IS NOT THE FILTER LIST. Both are validated against ReportCatalogue — twice, once
 * on save and again on run (§84), because a definition outlives the register it names. But they are
 * validated against DIFFERENT lists: a title may be printed and never filtered on, because showing
 * somebody a row they may see is not a disclosure while letting them test a guess against every row
 * they may not is. That asymmetry is argued in ReportCatalogue and enforced here.
 */
class ReportDefinition extends Model
{
    use Auditable, BelongsToOrganization, Scopeable;

    /** @var array<string,string> */
    public const DIRECTIONS = [
        'asc' => 'Smallest first',
        'desc' => 'Largest first',
    ];

    /**
     * How a report can be taken away from the screen.
     *
     * Deliberately short. Excel and Word are named in docs/11 §3 and are not here, because a format
     * this cannot render is worse than a format it does not offer: a greyed-out button that fails on
     * click teaches people the report itself is unreliable.
     *
     * @var array<string,string>
     */
    public const FORMATS = [
        'csv' => 'CSV (spreadsheet)',
        'pdf' => 'PDF (print)',
    ];

    /**
     * The most columns one report may print.
     *
     * A page-width limit rather than a query one. Forty columns is a spreadsheet somebody exports
     * and never opens, and the report that gets read is the one that fits.
     */
    public const MAX_COLUMNS = 15;

    /** @see KpiDefinition::MAX_FILTERS — the same ceiling, for the same comprehensibility reason. */
    public const MAX_FILTERS = 10;

    /**
     * The most rows one run will return.
     *
     * A HARD CEILING AND NOT A PAGE SIZE. docs/11 §3 sends anything larger down a queued path with
     * a signed expiring link, and that path is Phase 8. Until it exists, a report that would return
     * a hundred thousand rows must say so and stop rather than take the server down proving it
     * could (§95) — and a truncated export that did not announce itself would be worse than either,
     * because somebody would reconcile against it.
     */
    public const MAX_ROWS = 5000;

    public const DEFAULT_ROW_LIMIT = 500;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'filters' => 'array',
            'sort' => 'integer',
            'row_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ================================================================== relations

    /** @return BelongsTo<User,$this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    // ================================================================== access hooks

    /**
     * A report definition is company reference data, exactly as a KPI definition is.
     *
     * The same three overrides for the same three reasons: this table has no `org_unit_id` and no
     * `project_id`, so leaving Scopeable's defaults in place would emit SQL naming columns that do
     * not exist — a 500 on the reports list for everybody whose scope is narrower than the company.
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
     * Seeing that a report EXISTS is organization-wide. Its ROWS never are.
     *
     * This is the one place the distinction has to be stated out loud, because it looks like a hole
     * and is the opposite. The definition is a question — "open incidents by department" — and a
     * question discloses nothing; a department head who could not see the list would find the
     * reports page empty and conclude the feature was broken. The answer is computed by
     * ReportRunner through `visibleTo()`, so the same report opened by two people returns two
     * different sets of rows. Only the read ability is listed here: `report.edit` staying scoped
     * is what stops "manage reports for my department" from becoming "rewrite every company
     * report".
     *
     * @return array<int,string>
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['report.view'];
    }

    public static function permissionModule(): string
    {
        return 'report';
    }

    // ================================================================== reading

    /**
     * The columns this report prints, in order, as the register still offers them.
     *
     * FILTERED ON THE WAY OUT, not merely on the way in. A column withdrawn from a register after
     * this report was written simply stops appearing, which is the behaviour that lets a report
     * survive a schema change instead of 500ing on one (§84).
     *
     * @return array<int,string>
     */
    public function selectedColumns(): array
    {
        $columns = [];

        foreach ((array) ($this->columns ?? []) as $column) {
            if (is_string($column) && ReportCatalogue::offersColumn($this->entity_type, $column)) {
                $columns[] = $column;
            }
        }

        return array_slice(array_values(array_unique($columns)), 0, self::MAX_COLUMNS);
    }

    /**
     * The conditions every listed row must meet.
     *
     * Cleaned here the way KpiDefinition cleans its own, and for the reason given there: a
     * definition written by an earlier validator, a seeder or a support call must behave rather
     * than reach the query builder malformed.
     *
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    public function baseFilters(): array
    {
        $clean = [];

        foreach ((array) ($this->filters ?? []) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $field = isset($filter['field']) ? (string) $filter['field'] : '';
            $operator = isset($filter['operator']) ? (string) $filter['operator'] : '';

            if ($field === '' || ! array_key_exists($operator, KpiDefinition::FILTER_OPERATORS)) {
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

    public function isGrouped(): bool
    {
        return filled($this->group_field)
            && ReportCatalogue::offersGroup($this->entity_type, (string) $this->group_field);
    }

    public function rowCeiling(): int
    {
        $limit = (int) ($this->row_limit ?: self::DEFAULT_ROW_LIMIT);

        return max(1, min($limit, self::MAX_ROWS));
    }

    /** The permission rows are listed under. Never anything wider than reading the register. */
    public function viewPermissionFor(string $class): string
    {
        return method_exists($class, 'defaultViewPermission')
            ? $class::defaultViewPermission()
            : $this->entity_type.'.view';
    }

    public function displayName(): string
    {
        return $this->name;
    }
}
