<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Http\Requests\ProjectDepartmentRequest;
use App\Models\Employee;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectDepartment;
use App\Services\ProjectTeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
