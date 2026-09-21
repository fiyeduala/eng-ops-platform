<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Kpi\KpiEvaluator;
use App\Models\KpiDefinition;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Support\KpiCatalogue;
use App\Support\RecordPresenter;
use App\Support\ReportCatalogue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The step underneath a figure (§89).
 *
 * A DASHBOARD THAT CANNOT BE INTERROGATED IS A DASHBOARD NOBODY TRUSTS. "Fourteen overdue
 * deliverables" is a number somebody either acts on or argues with, and the difference between the
 * two is whether they can see which fourteen. docs/05 §8 calls this the drill-down path and
 * requires context to be carried in the URL and re-authorized at every level; this is the level
 * directly below a tile.
 *
 * THE LIST IS NOT DERIVED FROM THE FIGURE — IT IS THE FIGURE'S OWN QUERY. `KpiEvaluator::population()`
 * hands back the identical builder the count ran on, so a tile reading 41 and this page listing 41
 * rows is a structural fact rather than something to keep in step. Writing the query again here,
 * from the same definition, is the obvious implementation and the one that silently goes wrong the
 * first time a filter type is added to one and not the other.
 *
 * WHICH ALSO SETTLES THE AUTHORIZATION. That builder begins with `visibleTo($viewer, …)`, so this
 * page cannot list a record its reader could not have found on the register's own index page. The
 * figure and the list are narrowed for the same person in the same way — which is what docs/11 §1
 * asks for, and is why there is no policy call in `measure()` doing a second, different check.
 *
 * WHAT IS CHECKED HERE, BECAUSE THE BUILDER CANNOT CHECK IT: the register's read permission, and
 * the scope id in the URL. The permission is checked because holding a dashboard permission is not
 * holding `document.view` — a board reader must not reach a document list through a tile that
 * happens to count documents (§6, §84). The scope id is checked because it is a URL id and is
 * therefore somebody's suggestion, never a fact (§84, §94): an id the reader cannot see is a 404,
 * not an empty table, because an empty table and a forbidden one look identical and only one of
 * them is honest.
 */
class DrilldownController extends Controller
{
    /** How many records one page of a drill-down lists. */
    private const PER_PAGE = 25;

    /** The most columns a generic list prints before it stops being readable. */
    private const MAX_COLUMNS = 6;

    public function __construct(
        private readonly KpiEvaluator $evaluator,
    ) {}

    public function measure(Request $request, KpiDefinition $kpiDefinition): View
    {
        $user = $request->user();

        $class = KpiCatalogue::modelFor((string) $kpiDefinition->entity_type);

        // A measure counting a register the platform no longer offers has no records to show and
        // no permission to check, which makes it unlistable rather than forbidden.
        abort_if($class === null, 404);

        // The register's own read permission, asked the way every tile asks it. A dashboard grant
        // opens a board; it does not open the registers the board counts.
        abort_unless($user->hasPermission($kpiDefinition->viewPermissionFor($class)), 403);

        $scopeId = $this->scopeId($request, $kpiDefinition);

        $result = $this->evaluator->evaluate($kpiDefinition, $user, null, $scopeId);
        $query = $this->evaluator->population($kpiDefinition, $user, null, $scopeId);

        $columns = $this->columnsFor((string) $kpiDefinition->entity_type);

        return view('drilldown.measure', [
            'kpi' => $kpiDefinition,
            'result' => $result,
            'columns' => $columns,
            'records' => $query === null ? null : $this->page($query, $columns, $kpiDefinition),
            'scopeLabel' => $this->scopeLabel($kpiDefinition, $scopeId),
            'entityLabel' => KpiCatalogue::countableEntities()[$kpiDefinition->entity_type] ?? 'Records',
        ]);
    }

    /**
     * One page of the population, newest first.
     *
     * Ordered by primary key descending rather than by a date column: not every register carries
     * one, and a list whose order changes between page one and page two shows some records twice
     * and others never.
     *
     * @param  Builder<Model>  $query
     * @param  array<string,string>  $columns
     * @return LengthAwarePaginator<int,Model>
     */
    private function page($query, array $columns, KpiDefinition $kpi): LengthAwarePaginator
    {
        $model = $query->getModel();

        // §89, and not merely for speed: lazy loading is prevented outside production, so a foreign
        // key printed as the record it points at would throw twenty-five times down this page
        // rather than simply being slow.
        $relations = [];

        foreach (array_keys($columns) as $column) {
            $relation = ReportCatalogue::relationFor((string) $kpi->entity_type, $column);

            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        if ($relations !== []) {
            $query->with(array_unique($relations));
        }

        return $query
            ->orderByDesc($model->qualifyColumn($model->getKeyName()))
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The columns worth printing for a register nobody chose columns for.
     *
     * A drill-down has no saved column list — it is reached from a tile, not from a builder — so it
     * takes the front of the register's own printable list. That list is ReportCatalogue's, which
     * has already removed everything that must never be printed: stored paths, tokens, hashes and
     * the tenant key (§67).
     *
     * @return array<string,string>
     */
    private function columnsFor(string $entityType): array
    {
        $offered = ReportCatalogue::columnsFor($entityType);

        // The identifying columns first where the register has them, so the reader recognises a row
        // before they read across it. Anything else follows in the register's own order.
        $preferred = [];

        foreach (['number', 'reference', 'code', 'title', 'name', 'subject', 'status_id', 'project_id', 'org_unit_id'] as $column) {
            if (array_key_exists($column, $offered)) {
                $preferred[$column] = $offered[$column];
                unset($offered[$column]);
            }
        }

        return array_slice($preferred + $offered, 0, self::MAX_COLUMNS, true);
    }

    /**
     * The scope this drill-down is about, or null.
     *
     * READ FROM THE QUERY STRING AND THEN PROVEN, in that order and never the other way. The id
     * arrives from a link somebody can retype, so it is confirmed against the reader's own
     * visibility before it is used to narrow anything: a department they cannot see is a 404 here,
     * rather than an intersection that quietly comes back empty and reads as "none" (§84, §94).
     */
    private function scopeId(Request $request, KpiDefinition $kpi): ?int
    {
        $raw = $request->query('scope');

        if ($raw === null || $raw === '' || ! ctype_digit((string) $raw)) {
            return null;
        }

        $scopeId = (int) $raw;
        $user = $request->user();

        $exists = match ($kpi->scope) {
            'department' => OrgUnit::query()->visibleTo($user, 'org_unit.view')->whereKey($scopeId)->exists(),
            'project' => Project::query()->visibleTo($user, 'project.view')->whereKey($scopeId)->exists(),
            // An employee-scoped measure narrows on a personnel record. There is no reason for a
            // board to link into one person's figures, so it is not offered rather than guessed at.
            default => false,
        };

        abort_unless($exists, 404);

        return $scopeId;
    }

    private function scopeLabel(KpiDefinition $kpi, ?int $scopeId): ?string
    {
        if ($scopeId === null) {
            return null;
        }

        $record = match ($kpi->scope) {
            'department' => OrgUnit::query()->find($scopeId),
            'project' => Project::query()->find($scopeId),
            default => null,
        };

        return $record === null ? null : RecordPresenter::describe($record);
    }
}
