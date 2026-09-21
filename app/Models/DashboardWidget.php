<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\KpiCatalogue;
use App\Support\ReportCatalogue;
use App\Support\WidgetCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tile on one board.
 *
 * A PLACEMENT, NOT A FIGURE — the same distinction KpiDefinition draws and for the same reason.
 * Nothing here is a number. The row says which measure or report to show, in what shape, at what
 * size, on which board; what appears in the tile is counted at render time through the visibility
 * of whoever opened the page, or lifted from a snapshot that person's reach is wide enough to
 * contain. Two people open the same board and correctly see different numbers under the same
 * heading, and on some boards see a different set of tiles entirely.
 *
 * THE PERMISSION IS NOT STORED AND CANNOT BE. `requiredPermission()` derives it from the source —
 * the register the measure counts — so a tile can never be published under a looser permission than
 * the records behind it. There is no column to edit and no form field to tamper with (§6, §84).
 *
 * IT IS AUDITED BECAUSE REARRANGING A BOARD IS A DECISION. Not because tiles are dangerous: a board
 * grants nothing and moving a tile moves no record. But "why did the safety figure come off the
 * executive board in March" is a question somebody eventually asks, usually in the week after it
 * mattered, and a dashboard with no history cannot answer it.
 */
class DashboardWidget extends Model
{
    use Auditable, BelongsToOrganization, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'row_limit' => 'integer',
            'span' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ================================================================== relations

    public function kpiDefinition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class);
    }

    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }

    // ================================================================== scopes

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeOnBoard(Builder $query, string $board): Builder
    {
        return $query->where('board', $board);
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    // ================================================================== access hooks

    /**
     * A tile belongs to the company, not to a department or a project.
     *
     * Same reasoning as KpiDefinition: unreset, Scopeable's defaults would have the compiler name
     * `org_unit_id` and `project_id`, columns this table does not have. The DEPARTMENT board is
     * about a department; the ROW describing it still belongs to the company that arranged it.
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
     * Reading the arrangement reaches every tile inside the tenant.
     *
     * A narrower scope cannot select a subset of company-level configuration — it selects nothing,
     * and a department head would open their board to find it empty. Safe because the arrangement
     * is not the data: seeing that a tile called "Open incidents" exists tells somebody nothing
     * about incidents, and the figure inside it is counted through their own visibility anyway.
     *
     * @return array<int,string>
     */
    public function accessOrganizationWideAbilities(): array
    {
        // `manage` is on the list for a sharper reason than the others. The compiler's alternative
        // to this fast path is a set of grant clauses naming `org_unit_id` and `project_id`, which
        // this table does not have — so a department-scoped manage grant would compile to a
        // predicate matching nothing, and an administrator would open the builder to find the
        // company's own tiles missing rather than be told they may not see them.
        return ['dashboard.view', 'dashboard.view_executive', 'dashboard.view_department', 'dashboard.manage'];
    }

    public static function permissionModule(): string
    {
        return 'dashboard';
    }

    // ================================================================== reading

    /**
     * What this tile is called on the board.
     *
     * The company's own phrase wins; the measure's name is the fallback rather than the default,
     * so renaming a measure renames the tiles that never chose their own wording and leaves alone
     * the ones that did.
     */
    public function heading(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        return $this->source()?->displayName() ?? 'Untitled tile';
    }

    /** The definition behind this tile, whichever kind of definition that is. */
    public function source(): KpiDefinition|ReportDefinition|null
    {
        if (WidgetCatalogue::usesKpi((string) $this->kind)) {
            return $this->kpiDefinition;
        }

        if (WidgetCatalogue::usesReport((string) $this->kind)) {
            return $this->reportDefinition;
        }

        return null;
    }

    /**
     * The permission somebody must hold for this tile to appear at all.
     *
     * DERIVED, never stored. A tile counting incidents requires whatever reading the incident
     * register requires, and asking the source each time means a register that tightens its own
     * permission tightens every dashboard quoting it on the next page load, with nothing to
     * migrate and no stale copy to find.
     */
    public function requiredPermission(): ?string
    {
        $source = $this->source();

        if ($source === null) {
            return null;
        }

        $class = KpiCatalogue::modelFor((string) $source->entity_type)
            ?? ReportCatalogue::modelFor((string) $source->entity_type);

        if ($class === null) {
            return null;
        }

        return $source->viewPermissionFor($class);
    }

    /**
     * Does this tile want last night's reading?
     *
     * A WANT, not a right. WidgetComposer asks ReachInspector before honouring it, and a viewer
     * whose reach falls short of the scope gets their own live figure instead.
     */
    public function prefersStored(): bool
    {
        return WidgetCatalogue::alwaysStored((string) $this->kind) || $this->refresh === 'stored';
    }

    public function displayName(): string
    {
        return $this->heading();
    }
}
