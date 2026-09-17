<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\CostCenterRequest;
use App\Models\CostCenter;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\User;
use App\Services\CostCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CostCenterController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly CostCenterService $centers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CostCenter::class);
        $user = $request->user();
        $query = $this->compiler->apply(CostCenter::query(), $user, 'budget.view')
            ->with(['orgUnit:id,name', 'project:id,name', 'manager:id,name'])->withCount('budgets')
            ->search((string) $request->query('q'));
        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return view('cost-centers.index', [
            'centers' => $query->orderBy('code')->paginate(40)->withQueryString(),
            'canCreate' => $user->can('create', CostCenter::class),
            'filters' => ['q' => $request->query('q'), 'active' => $request->query('active')],
        ]);
    }

    public function show(Request $request, CostCenter $costCenter): View
    {
        $this->authorize('view', $costCenter);
        $costCenter->load(['orgUnit', 'project', 'manager']);
        $costCenter->load(['budgets' => fn ($query) => $query->visibleTo($request->user(), 'budget.view')->with('status')]);

        return view('cost-centers.show', [
            'center' => $costCenter,
            'timeline' => $this->activity->timelineFor($costCenter)->limit(25)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', CostCenter::class);

        return view('cost-centers.create', ['center' => new CostCenter(['is_active' => true]), ...$this->options($request)]);
    }

    public function store(CostCenterRequest $request): RedirectResponse
    {
        $this->authorize('create', CostCenter::class);
        $this->assertPlacement($request->costCenterAttributes(), $request->user());
        $center = $this->centers->create($request->costCenterAttributes(), $request->user());

        return redirect()->route('cost-centers.show', $center)->with('status', $center->displayName().' has been created.');
    }

    public function edit(Request $request, CostCenter $costCenter): View
    {
        $this->authorize('update', $costCenter);

        return view('cost-centers.edit', ['center' => $costCenter, ...$this->options($request)]);
    }

    public function update(CostCenterRequest $request, CostCenter $costCenter): RedirectResponse
    {
        $this->authorize('update', $costCenter);
        $this->assertPlacement($request->costCenterAttributes(), $request->user());
        $this->centers->update($costCenter, $request->costCenterAttributes(), $request->user());

        return redirect()->route('cost-centers.show', $costCenter)->with('status', $costCenter->displayName().' has been updated.');
    }

    public function destroy(CostCenter $costCenter): RedirectResponse
    {
        $this->authorize('delete', $costCenter);
        if ($costCenter->budgets()->exists()) {
            return back()->withErrors(['delete' => 'A cost center with budgets cannot be deleted. Make it inactive instead.']);
        }
        $this->activity->record($costCenter, 'deleted', 'The cost center '.$costCenter->displayName().' was deleted.', [], request()->user(), $costCenter->project_id);
        $costCenter->delete();

        return redirect()->route('cost-centers.index')->with('status', 'The cost center has been deleted.');
    }

    private function options(Request $request): array
    {
        $user = $request->user();

        return [
            'projects' => Project::visibleTo($user, 'project.view')->orderBy('name')->pluck('name', 'id')->all(),
            'units' => $this->compiler->apply(OrgUnit::query(), $user, 'org_unit.view')->orderBy('name')->pluck('name', 'id')->all(),
            'managers' => $this->compiler->apply(User::query()->where('is_active', true), $user, 'employee.view')->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    private function assertPlacement(array $data, User $user): void
    {
        if (($project = $data['project_id'] ?? null) !== null
            && ! Project::visibleTo($user, 'project.view')->whereKey($project)->exists()) {
            abort(403);
        }
        if (($unit = $data['org_unit_id'] ?? null) !== null
            && ! $this->compiler->apply(OrgUnit::query(), $user, 'org_unit.view')->whereKey($unit)->exists()) {
            abort(403);
        }
        if (($manager = $data['manager_id'] ?? null) !== null
            && ! $this->compiler->apply(User::query(), $user, 'employee.view')->whereKey($manager)->exists()) {
            abort(403);
        }
    }
}
