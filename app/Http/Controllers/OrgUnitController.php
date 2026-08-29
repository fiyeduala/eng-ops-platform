<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Organization\OrgUnitService;
use App\Http\Requests\OrgUnitRequest;
use App\Models\Employee;
use App\Models\OrgUnit;
use App\Support\Enums\OrgUnitType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The configurable organization tree (§3, §72, §73).
 *
 * Two things here are worth reading twice.
 *
 * First, the list is drawn from ScopeCompiler, so a department head sees their own subtree and
 * nothing above it. That means the tree they see may have no root — their department's parent
 * division is simply absent. The view indents relative to the shallowest visible node rather than
 * to absolute depth, because indenting by absolute depth would push their whole world off the
 * right of the screen and, worse, would silently tell them how many levels exist above them.
 *
 * Second, moving a unit is not the same authority as renaming one (§3): moving a department moves
 * every person's visibility with it. `parent_id` is therefore read from the request ONLY when the
 * actor holds org_unit.reorganize over both ends of the move; otherwise the submitted value is
 * discarded rather than trusted (§84).
 */
class OrgUnitController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly OrgUnitService $service,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', OrgUnit::class);

        $user = $request->user();

        $query = $this->compiler->apply(OrgUnit::query(), $user, 'org_unit.view')
            ->with('head:id,first_name,last_name,display_name');

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%');
            });
        }

        if ($type = $request->query('type')) {
            if (OrgUnitType::tryFrom((string) $type) !== null) {
                $query->where('type', $type);
            }
        }

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        // Ordering by the materialized path is what makes a flat list read as a tree: every child
        // sorts immediately after its parent, at any depth, in one indexed sort.
        $units = $query->orderBy('path')->get();

        $baseDepth = (int) ($units->min('depth') ?? 0);

        return view('org-units.index', [
            'units' => $units,
            'baseDepth' => $baseDepth,
            'headcounts' => $this->headcounts($request, $units),
            'types' => OrgUnitType::options(),
            'filters' => [
                'q' => $request->query('q'),
                'type' => $request->query('type'),
                'include_inactive' => $request->boolean('include_inactive'),
            ],
            'canCreate' => $user->can('create', OrgUnit::class),
        ]);
    }

    public function show(Request $request, OrgUnit $orgUnit): View
    {
        $this->authorize('view', $orgUnit);

        $user = $request->user();

        // Children are re-filtered rather than loaded through the relation: reach over a parent
        // does not automatically imply reach over every child, and a relation load would bypass
        // the compiler entirely.
        $children = $this->compiler
            ->apply(OrgUnit::query()->where('parent_id', $orgUnit->id), $user, 'org_unit.view')
            ->orderBy('sort')->orderBy('name')->get();

        $people = collect();

        if ($user->hasPermission('employee.view')) {
            $people = $this->compiler
                ->apply(Employee::query()->active()->where('org_unit_id', $orgUnit->id), $user, 'employee.view')
                ->with('position:id,title')
                ->orderBy('last_name')->orderBy('first_name')
                ->limit(50)
                ->get();
        }

        return view('org-units.show', [
            'unit' => $orgUnit->loadMissing(['head', 'parent']),
            'ancestors' => $this->visibleAncestors($request, $orgUnit),
            'children' => $children,
            'people' => $people,
            'canEdit' => $user->can('update', $orgUnit),
            'canDelete' => $user->can('delete', $orgUnit),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', OrgUnit::class);

        return view('org-units.create', [
            'unit' => new OrgUnit(['type' => OrgUnitType::Department, 'is_active' => true]),
            'types' => OrgUnitType::options(),
            'parents' => $this->parentOptions($request, 'org_unit.create'),
            'heads' => $this->headOptions($request),
        ]);
    }

    public function store(OrgUnitRequest $request): RedirectResponse
    {
        $this->authorize('create', OrgUnit::class);

        $data = $request->validated();

        if (($parentId = $data['parent_id'] ?? null) !== null) {
            // Reach over the PARENT is required to hang a new unit off it. Without this check the
            // create form's select could be edited to name any unit id in the tenant.
            $parent = OrgUnit::findOrFail($parentId);
            $this->authorize('view', $parent);
        }

        // Never from input. §74.
        $data['organization_id'] = $request->user()->organization_id;

        $unit = $this->service->create($data);

        $this->activity->record($unit, 'created', $request->user()->name.' created '.$unit->name);

        return redirect()->route('org-units.show', $unit)
            ->with('status', $unit->name.' has been created.');
    }

    public function edit(Request $request, OrgUnit $orgUnit): View
    {
        $this->authorize('update', $orgUnit);

        $canReorganize = $request->user()->can('reorganize', [$orgUnit, $orgUnit->parent]);

        return view('org-units.edit', [
            'unit' => $orgUnit,
            'types' => OrgUnitType::options(),
            'parents' => $canReorganize ? $this->parentOptions($request, 'org_unit.reorganize', $orgUnit) : [],
            'heads' => $this->headOptions($request),
            'canReorganize' => $canReorganize,
        ]);
    }

    public function update(OrgUnitRequest $request, OrgUnit $orgUnit): RedirectResponse
    {
        $this->authorize('update', $orgUnit);

        $data = $request->validated();

        $requestedParentId = array_key_exists('parent_id', $data)
            ? ($data['parent_id'] !== null ? (int) $data['parent_id'] : null)
            : $orgUnit->parent_id;

        if ($requestedParentId !== $orgUnit->parent_id) {
            // A move, not an edit. Authorize against BOTH ends, then let the service reject
            // structurally impossible destinations.
            $newParent = $requestedParentId !== null ? OrgUnit::findOrFail($requestedParentId) : null;

            $this->authorize('reorganize', [$orgUnit, $newParent]);
        } else {
            // Not a move: whatever the form said about parent_id is irrelevant, so it is dropped
            // rather than passed through. An edit form is not an authorization to restructure.
            $data['parent_id'] = $orgUnit->parent_id;
        }

        $unit = $this->service->update($orgUnit, $data);

        $this->activity->record($unit, 'updated', $request->user()->name.' updated '.$unit->name);

        return redirect()->route('org-units.show', $unit)
            ->with('status', $unit->name.' has been updated.');
    }

    public function destroy(Request $request, OrgUnit $orgUnit): RedirectResponse
    {
        // The policy refuses while the unit still has children or people in it — deleting it
        // would orphan their records and silently change who can see them.
        $this->authorize('delete', $orgUnit);

        $name = $orgUnit->name;
        $parent = $orgUnit->parent;

        $this->activity->record($orgUnit, 'deleted', $request->user()->name.' removed '.$name);

        $orgUnit->delete();
        $orgUnit->organization?->bumpPermissionsVersion();

        return redirect()->route($parent ? 'org-units.show' : 'org-units.index', $parent ? [$parent] : [])
            ->with('status', $name.' has been removed.');
    }

    // ------------------------------------------------------------------ options

    /**
     * Units this actor may hang something off, as a value => label map.
     *
     * @return array<int|string,string>
     */
    private function parentOptions(Request $request, string $permission, ?OrgUnit $excluding = null): array
    {
        $query = $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), $permission);

        if ($excluding !== null) {
            // A unit may not be moved inside itself or inside its own subtree. The service would
            // refuse anyway; removing the options stops the form offering a choice that cannot work.
            $query->where('path', 'not like', $excluding->path.'%');
        }

        return $query->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $unit) => [
                $unit->id => str_repeat('— ', max(0, (int) $unit->depth)).$unit->name,
            ])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function headOptions(Request $request): array
    {
        if (! $request->user()->hasPermission('employee.view')) {
            return [];
        }

        return $this->compiler
            ->apply(Employee::query()->active(), $request->user(), 'employee.view')
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()])
            ->all();
    }

    /**
     * Ancestors the viewer may actually see, nearest last.
     *
     * A department head sees their department but not the division above it, so the breadcrumb
     * stops where their reach stops rather than naming a unit they cannot open.
     *
     * @return Collection<int,OrgUnit>
     */
    private function visibleAncestors(Request $request, OrgUnit $unit): Collection
    {
        $ids = $unit->ancestorIds();

        if ($ids === []) {
            return collect();
        }

        return $this->compiler
            ->apply(OrgUnit::query()->whereIn('id', $ids), $request->user(), 'org_unit.view')
            ->orderBy('depth')
            ->get();
    }

    /**
     * Headcount per unit, counted through the personnel scope rather than the structure scope.
     *
     * Two different questions — "which units may I see" and "which people may I see" — and a
     * viewer can hold the first without the second. Where they hold neither, the column is absent
     * rather than zero, because a row of zeroes reads as "this department is empty".
     *
     * @param  Collection<int,OrgUnit>  $units
     * @return array<int,int>|null
     */
    private function headcounts(Request $request, Collection $units): ?array
    {
        $user = $request->user();

        if ($units->isEmpty() || ! $user->hasPermission('employee.view')) {
            return null;
        }

        return $this->compiler
            ->apply(Employee::query()->active(), $user, 'employee.view')
            ->whereIn('org_unit_id', $units->pluck('id'))
            ->selectRaw('org_unit_id, count(*) as aggregate')
            ->groupBy('org_unit_id')
            ->pluck('aggregate', 'org_unit_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
