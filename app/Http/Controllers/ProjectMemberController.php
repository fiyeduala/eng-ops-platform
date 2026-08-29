<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Http\Requests\ProjectMemberRequest;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectRole;
use App\Models\User;
use App\Policies\ProjectMemberPolicy;
use App\Services\ProjectTeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The project team — the write side of the project visibility axis (§12, §14).
 *
 * Creating a row here widens what somebody else can see, which makes every method below an
 * authorization boundary rather than nested CRUD. Two checks run on every add, and the second is
 * the one a normal resource controller would omit:
 *
 *   1. May this actor staff THIS project?  — ProjectMemberPolicy::assign($user, $project)
 *   2. May this actor commit THIS person?  — the same call, with the candidate
 *
 * Without (2), any holder of `project_member.assign` could add the Managing Director's account to
 * a project they run and read the company through them. The candidate arrives as a plain integer
 * in a POST body, so the check has to happen here, with the resolved account in hand (§84).
 *
 * The route uses scoped bindings, so `/projects/7/team/93` 404s when member 93 belongs to a
 * different project — the IDOR case §94 asks to be tested.
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ProjectTeamService $team,
    ) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', ProjectMember::class);

        $user = $request->user();

        // Re-filtered, not merely loaded: being able to open a project is not being able to see
        // every personnel record attached to it, and a team page that ignored that would be a way
        // around employee.view (§61).
        $members = $this->compiler
            ->apply($project->members()->getQuery(), $user, 'project_member.view')
            ->with(['user:id,name,email', 'projectRole:id,name,is_lead', 'orgUnit:id,name', 'addedBy:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        return view('projects.team', [
            'project' => $project,
            'members' => $members,
            'roles' => ProjectRole::query()->active()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all(),
            'units' => $this->unitOptions($request),
            'candidates' => $this->candidateOptions($request, $project),
            'canAssign' => $user->can('assign', [ProjectMember::class, $project]),

            // Gate 2 only, and used only to decide whether to render the edit column at all.
            // Whether this actor may change a PARTICULAR row is asked per row, with @can.
            'canManage' => $user->hasPermission('project_member.manage'),
        ]);
    }

    public function store(ProjectMemberRequest $request, Project $project): RedirectResponse
    {
        $data = $request->validated();

        $candidate = User::findOrFail((int) $data['user_id']);

        // Both questions, with the project and the person resolved. The picker on the page already
        // excludes anyone this actor may not commit; that is a convenience, and this is the rule.
        $this->authorize('assign', [ProjectMember::class, $project, $candidate]);

        $this->assertUnitIsReachable($request, $data);

        $member = $this->team->addMember($project, $data, $request->user());

        return redirect()->route('projects.team', $project)
            ->with('status', ($member->user?->name ?? 'The team member').' has been added to the team.');
    }

    public function update(ProjectMemberRequest $request, Project $project, ProjectMember $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $data = $request->validated();

        $this->assertUnitIsReachable($request, $data);

        $this->team->updateMember($member, $data, $request->user());

        return redirect()->route('projects.team', $project)
            ->with('status', 'The assignment has been updated.');
    }

    public function destroy(Request $request, Project $project, ProjectMember $member): RedirectResponse
    {
        // Deliberately not the mirror of assign(). Removing someone REVOKES visibility, so reach
        // over the project is enough; a manager who inherited a team member from a department they
        // cannot see must still be able to take them off the job.
        $this->authorize('remove', $member);

        $name = $member->user?->name ?? 'The team member';

        $this->team->removeMember($member, $request->user());

        return redirect()->route('projects.team', $project)
            ->with('status', $name.' has been removed from the team. They can no longer see this project.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The department recorded on a membership row is reporting data, but it is still a foreign key
     * arriving in a POST, and filing an assignment against a unit the actor cannot see is a way of
     * pushing a record out of their own reach.
     *
     * @param  array<string,mixed>  $data
     */
    private function assertUnitIsReachable(Request $request, array $data): void
    {
        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }
    }

    /**
     * People this actor may actually put on this project.
     *
     * The list is built by asking the policy about each candidate rather than by reimplementing
     * its rules in a query — the same answer the POST will get, so the form never offers a name
     * that the save would refuse. Capped, because a picker is not a directory export (§61).
     *
     * @return array<int|string,string>
     */
    private function candidateOptions(Request $request, Project $project): array
    {
        $user = $request->user();

        if (! $user->hasPermission('user.view')) {
            return [];
        }

        $policy = app(ProjectMemberPolicy::class);

        $taken = $project->members()->pluck('user_id');

        return $this->compiler
            ->apply(User::query()->active(), $user, 'user.view')
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->filter(fn (User $candidate) => $policy->assign($user, $project, $candidate))
            ->mapWithKeys(fn (User $candidate) => [$candidate->id => $candidate->name])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function unitOptions(Request $request): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }
}
