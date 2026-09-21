<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ActorProfile;
use App\Engines\Access\ScopeCompiler;
use App\Http\Requests\ProjectDepartmentRequest;
use App\Models\Employee;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectDepartment;
use App\Models\User;
use App\Services\ProjectTeamService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Which departments are committed to a project — step 1 of the §13 cascade.
 *
 * Assigning a department does NOT let anyone in it see the project. It puts the project on that
 * department head's list so they can decide who works on it; the staffing is what grants reach,
 * and that is ProjectMemberController's job. Keeping the two apart is what makes "the department
 * decides which of its people are committed" a real statement rather than a description of a bulk
 * grant that already happened.
 *
 * Guarded by `project.manage` rather than `project.edit`: reshaping which departments are
 * responsible for a job is a project-management act, not a correction to a description.
 */
class ProjectDepartmentController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ProjectTeamService $team,
        private readonly ActorProfile $profile,
    ) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $user = $request->user();

        $departments = $project->departments()
            ->with(['orgUnit:id,name,type,path', 'lead:id,first_name,last_name', 'assignedBy:id,name'])
            ->orderBy('id')
            ->get();

        // How many people each department has actually committed. This is the number that tells a
        // project manager whether step 3 of the cascade has happened, and it is the honest measure
        // of whether a department has engaged with the job at all.
        $staffed = $project->members()
            ->where('is_active', true)
            ->whereNotNull('org_unit_id')
            ->selectRaw('org_unit_id, COUNT(*) as total')
            ->groupBy('org_unit_id')
            ->pluck('total', 'org_unit_id');

        return view('projects.departments', [
            'project' => $project,
            'departments' => $departments,
            'staffed' => $staffed,
            'units' => $this->unitOptions($request, $project),
            'leads' => $this->leadOptions($request),
            'canManage' => $user->can('manage', $project),
        ]);
    }

    /**
     * One department's slice of one project (§89, docs/05 §8, docs/11 §4).
     *
     * THE MIDDLE STEP OF THE DRILL-DOWN PATH, and the one that makes the rest of it worth having:
     * "Project Alpha 72%" is a number to quote, "Engineering on Project Alpha: 14 deliverables, 3
     * overdue" is a number to act on. docs/11 §4 draws the path as
     * project → department → deliverables · tasks · documents · RFIs, and this page is both the
     * department rung and the list of links to the one below it.
     *
     * AUTHORIZATION IS ASKED THREE TIMES HERE, at three different questions, and none of them is
     * redundant. The route's scoped binding proves the department belongs to THIS project, so
     * `/projects/7/departments/93` where 93 sits on project 8 is a 404 before this method runs.
     * `authorize('view', $project)` proves the reader may be on the project at all. And every
     * register below is counted through `visibleTo()`, so a reader who may open the project still
     * sees only the records of it they could have found on each register's own index page — the
     * drill-down carries context down the path, never authority (§84, §94).
     *
     * THE DEPARTMENT ROLLS UP, because an organogram is a tree so that it can be read as one.
     * "Engineering on this project" means the division and the sections under it, which is the same
     * reading KpiEvaluator gives a department-scoped measure — two screens disagreeing about what a
     * department contains is worse than either answer.
     */
    public function show(Request $request, Project $project, ProjectDepartment $department): View
    {
        $this->authorize('view', $project);

        $user = $request->user();

        $department->loadMissing(['orgUnit:id,name,type,path', 'lead:id,first_name,last_name', 'assignedBy:id,name']);

        $unitIds = $this->profile->subtreeOf((int) $department->org_unit_id);

        // The people this department has actually committed to the job. Re-filtered rather than
        // simply loaded, for the reason the project page gives: opening a project is not being
        // handed every personnel record attached to it (§61).
        $members = $this->compiler
            ->apply($project->members()->getQuery(), $user, 'project_member.view')
            ->whereIn('org_unit_id', $unitIds)
            ->with(['user:id,name', 'projectRole:id,name,is_lead'])
            ->orderByDesc('is_active')
            ->limit(50)
            ->get();

        return view('projects.department-slice', [
            'project' => $project,
            'department' => $department,
            'members' => $members,
            'registers' => $this->registers($user, (int) $project->getKey(), $unitIds),
            'canManage' => $user->can('manage', $project),
        ]);
    }

    /**
     * What this department has on this project, register by register.
     *
     * A WRITTEN LIST RATHER THAN EVERY REGISTER THAT HAPPENS TO CARRY BOTH COLUMNS. Two dozen
     * registers do, and a page offering all of them is a page nobody reads; these four are the ones
     * docs/11 §4 names as the step below a department, and they are the four a discipline lead
     * actually chases. A register whose module is not installed drops out on its own, because the
     * morph alias resolves to nothing.
     *
     * @param  array<int,int>  $unitIds
     * @return array<int,array{label:string,count:int,records:Collection<int,Model>}>
     */
    private function registers(User $user, int $projectId, array $unitIds): array
    {
        $rows = [];

        foreach (['deliverable', 'task', 'document', 'rfi'] as $alias) {
            $class = Relation::getMorphedModel($alias);

            if ($class === null || ! class_exists($class) || ! method_exists($class, 'scopeVisibleTo')) {
                continue;
            }

            $permission = $class::defaultViewPermission();

            // Absent rather than empty. A reader without `rfi.view` is not told how many RFIs there
            // are and then refused them — the heading itself is the disclosure (§61).
            if (! $user->hasPermission($permission)) {
                continue;
            }

            $query = $class::query()
                ->visibleTo($user, $permission)
                ->where('project_id', $projectId)
                ->whereIn('org_unit_id', $unitIds);

            $rows[] = [
                'label' => str(class_basename($class))->headline()->plural()->toString(),
                'count' => (clone $query)->count(),
                // A handful, newest first, as the sample behind the count. The count is the claim;
                // these are what makes it checkable without another page load.
                'records' => $query
                    ->orderByDesc((new $class)->getKeyName())
                    ->limit(5)
                    ->get(),
            ];
        }

        return $rows;
    }

    public function store(ProjectDepartmentRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validated();

        $this->assertReferencesAreReachable($request, $data);

        $assignment = $this->team->attachDepartment($project, $data, $request->user());

        return redirect()->route('projects.departments', $project)
            ->with('status', ($assignment->orgUnit?->name ?? 'The department')
                .' is now assigned. Its head can staff the project from their own people.');
    }

    public function update(ProjectDepartmentRequest $request, Project $project, ProjectDepartment $department): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validated();

        $this->assertReferencesAreReachable($request, $data);

        $this->team->updateDepartment($department, $data, $request->user());

        return redirect()->route('projects.departments', $project)
            ->with('status', 'The department assignment has been updated.');
    }

    public function destroy(Request $request, Project $project, ProjectDepartment $department): RedirectResponse
    {
        $this->authorize('manage', $project);

        $name = $department->orgUnit?->name ?? 'The department';

        $this->team->detachDepartment($department, $request->user());

        return redirect()->route('projects.departments', $project)
            ->with('status', $name.' is no longer assigned. Anyone from it already on the team stays on the team.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string,mixed>  $data
     */
    private function assertReferencesAreReachable(Request $request, array $data): void
    {
        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }

        if (($leadId = $data['lead_employee_id'] ?? null) !== null) {
            $this->authorize('view', Employee::findOrFail($leadId));
        }
    }

    /**
     * Units this actor can see, minus the ones already assigned.
     *
     * @return array<int|string,string>
     */
    private function unitOptions(Request $request, Project $project): array
    {
        $taken = $project->departments()->pluck('org_unit_id');

        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
            ->whereNotIn('id', $taken)
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function leadOptions(Request $request): array
    {
        if (! $request->user()->hasPermission('employee.view')) {
            return [];
        }

        return $this->compiler
            ->apply(Employee::query()->active(), $request->user(), 'employee.view')
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(500)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
            ->all();
    }
}
