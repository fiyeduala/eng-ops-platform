<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Audit\ActivityVisibility;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Dashboard\WidgetComposer;
use App\Http\Requests\ProjectRequest;
use App\Models\Location;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectType;
use App\Models\Status;
use App\Models\User;
use App\Services\ProjectService;
use App\Support\Enums\Confidentiality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Projects (§13, §14).
 *
 * The list on this page is the clearest demonstration of the two-axis access model in the whole
 * application. One ScopeCompiler call returns:
 *
 *   - every project in the departments a division head reaches (organizational axis), UNION
 *   - every project a person is a team member of, wherever it sits (project axis).
 *
 * A drafter in Structural Engineering therefore sees the mechanical project she was seconded to
 * without being able to see anything else mechanical — with no branch in this controller saying so.
 *
 * Two writes are deliberately not `update`. Status moves through the configured whitelist, so the
 * form has no status select and the transition endpoint re-checks the move server-side; archiving
 * is its own permission because "we are finished with this" is a different decision from "correct
 * this description".
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly ProjectService $projects,
        private readonly NumberGenerator $numbers,
        private readonly WidgetComposer $composer,
        private readonly ActivityVisibility $visibility,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();

        $query = $this->compiler->apply(Project::query(), $user, 'project.view')
            ->with(['type:id,name', 'status:id,label,color', 'orgUnit:id,name', 'manager:id,name'])
            ->withCount(['members' => fn ($q) => $q->where('is_active', true)]);

        $query->search((string) $request->query('q', ''));

        if ($typeId = (int) $request->query('type', 0)) {
            $query->where('project_type_id', $typeId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        // "Engineering" means Engineering and everything under it — a division head filtering to
        // their own division and getting only the projects filed directly against the division,
        // not against its departments, is the sort of half-answer that erodes trust in a list.
        if ($unitId = (int) $request->query('unit', 0)) {
            $unit = OrgUnit::find($unitId);

            if ($unit !== null && $user->can('view', $unit)) {
                $query->whereIn('org_unit_id', $unit->subtree()->select('id'));
            }
        }

        $view = (string) $request->query('view', 'active');

        match ($view) {
            'mine' => $query->where(fn ($q) => $q
                ->where('manager_id', $user->id)
                ->orWhere('sponsor_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->current())),
            'overdue' => $query->active()
                ->whereNotNull('planned_end')
                ->whereDate('planned_end', '<', now())
                ->whereHas('status', fn ($s) => $s->where('is_terminal', false)),
            'archived' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->active(),
        };

        /** @var LengthAwarePaginator $projects */
        $projects = $query->orderByDesc('planned_end')->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('projects.index', [
            'projects' => $projects,
            'types' => $this->typeOptions(),
            'statuses' => $this->statuses->optionsFor('project'),
            'units' => $this->unitOptions($request),
            'views' => $this->viewOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'type' => $request->query('type'),
                'status' => $request->query('status'),
                'unit' => $request->query('unit'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Project::class),
            'canSeeFinancials' => $user->hasPermission('project.view_financials'),
        ]);
    }

    public function show(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $user = $request->user();

        $project->loadMissing(['type', 'status', 'orgUnit', 'location', 'manager', 'sponsor', 'creator']);

        // The team and the departments are re-filtered rather than simply loaded. Seeing a project
        // is not seeing every personnel record attached to it, and a project page that renders a
        // full staff list would be a way around employee.view (§61).
        $members = $this->compiler
            ->apply($project->members()->getQuery(), $user, 'project_member.view')
            ->with(['user:id,name', 'projectRole:id,name,is_lead', 'orgUnit:id,name'])
            ->orderByDesc('is_active')
            ->limit(50)
            ->get();

        $departments = $project->departments()
            ->with(['orgUnit:id,name', 'lead:id,first_name,last_name'])
            ->orderBy('id')
            ->get();

        return view('projects.show', [
            'project' => $project,
            'members' => $members,
            'memberCount' => $project->members()->where('is_active', true)->count(),
            'departments' => $departments,
            // The project command center (§52's middle altitude, docs/05 §8). Composed for whoever
            // is looking, with the project carried as the scope — the same arrangement serves the
            // project manager and the division head and gives each of them their own figures.
            'tiles' => $this->composer->compose('project', $user, (int) $project->getKey()),
            // Fetched wide and filtered to subjects this person may open (§60). A project timeline
            // is the one place every module's activity meets, so it is also the place where an
            // unfiltered feed would hand a procurement clerk the HSE incidents raised on their
            // project — in sentences, with references.
            'timeline' => $this->visibility->filter(
                $this->activity->timelineForProject((int) $project->getKey())->limit(80)->get(),
                $user,
            )->take(25),
            'transitions' => $this->statuses->available('project', $project->status_id, $user),
            'canEdit' => $user->can('update', $project),
            'canManage' => $user->can('manage', $project),
            'canArchive' => $user->can('archive', $project),
            'canDelete' => $user->can('delete', $project),
            'canSeeFinancials' => $user->can('viewFinancials', $project),
            'canSeeTeam' => $user->can('viewAny', ProjectMember::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Project::class);

        return view('projects.create', [
            'project' => new Project([
                'confidentiality' => Confidentiality::Internal,
                'priority' => 'normal',
                'health' => 'on_track',
                'is_active' => true,
            ]),

            // No record exists yet, so there is nothing to ask a policy about: the question is
            // whether this actor deals in contract values at all. ProjectRequest::attributesFor()
            // uses the same test on the way back in.
            'canSeeFinancials' => $request->user()->hasPermission('project.view_financials'),
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $project = new Project;
        $data = $request->attributesFor($project);

        $this->assertPlacementIsReachable($request, $data);

        $project = $this->projects->create($data, $request->user());

        return redirect()->route('projects.show', $project)
            ->with('status', $project->displayName().' has been created.');
    }

    public function edit(Request $request, Project $project): View
    {
        $this->authorize('update', $project);

        return view('projects.edit', [
            'project' => $project,
            'canSeeFinancials' => $request->user()->can('viewFinancials', $project),
            ...$this->formOptions($request, $project),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->attributesFor($project);

        $this->assertPlacementIsReachable($request, $data);

        $this->projects->update($project, $data, $request->user());

        return redirect()->route('projects.show', $project)
            ->with('status', $project->displayName().' has been updated.');
    }

    /**
     * Move the project to another status.
     *
     * The buttons on the show page are drawn from StatusMachine::available(), and this endpoint
     * asks StatusMachine again with the same actor. A button that was not drawn is not a button
     * that cannot be pressed (§84) — the POST is the boundary, the rendering is a convenience.
     */
    public function transition(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // Fetched through Eloquent so the tenant global scope applies: a status id from another
        // company must not resolve at all, let alone be assignable.
        $to = Status::query()->forEntity('project')->whereKey($validated['status_id'])->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status a project can be in.']);
        }

        try {
            $this->projects->transition($project, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return redirect()->route('projects.show', $project)
            ->with('status', $project->displayName().' is now '.$to->label.'.');
    }

    public function archive(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('archive', $project);

        $this->projects->archive($project, $request->user());

        return redirect()->route('projects.show', $project)
            ->with('status', $project->displayName().' has been archived. Its records are intact.');
    }

    public function unarchive(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('archive', $project);

        $this->projects->unarchive($project, $request->user());

        return redirect()->route('projects.show', $project)
            ->with('status', $project->displayName().' is active again.');
    }

    /**
     * Delete — which the policy allows only for a project with no team and no departments, i.e.
     * one created by mistake. Anything that has been worked on is archived instead.
     */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $name = $project->displayName();

        $this->activity->record(
            $project,
            'deleted',
            $request->user()->name.' deleted '.$name,
            [],
            $request->user(),
            (int) $project->getKey(),
        );

        $project->delete();

        return redirect()->route('projects.index')
            ->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Every foreign key on the way in, re-authorized against the actor.
     *
     * The form only ever offers reachable options, but the form is not the security boundary: a
     * POST can name any id in the tenant. Filing a project into a department you cannot see moves
     * it out of your own reach; naming a manager you have no authority over hands that account the
     * project axis on a job nobody asked them to run — a membership row is created for the manager
     * automatically, so choosing one IS a visibility grant.
     *
     * @param  array<string,mixed>  $data
     */
    private function assertPlacementIsReachable(Request $request, array $data): void
    {
        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }

        foreach (['manager_id', 'sponsor_id'] as $key) {
            if (($userId = $data[$key] ?? null) !== null) {
                $this->authorize('view', User::findOrFail($userId));
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Project $project): array
    {
        return [
            'types' => $this->typeOptions(),
            'units' => $this->unitOptions($request),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'people' => $this->accountOptions($request),
            'confidentialities' => Confidentiality::options(),
            'priorities' => collect(ProjectRequest::priorities())
                ->mapWithKeys(fn (string $p) => [$p => ucfirst($p)])->all(),
            'healths' => collect(ProjectRequest::healths())
                ->mapWithKeys(fn (string $h) => [$h => ucwords(str_replace('_', ' ', $h))])->all(),

            // A preview only. Two people on this form see the same string and only one of them
            // gets it; the number is claimed in ProjectService, at save.
            'nextNumber' => $project === null ? $this->numbers->preview('project') : null,
        ];
    }

    /**
     * Accounts that can be named as manager or sponsor.
     *
     * Filtered through user.view rather than offered wholesale, because naming someone manager
     * seats them on the team, and a picker listing the whole company would be a way of discovering
     * who works here for someone whose access says otherwise (§61).
     *
     * @return array<int|string,string>
     */
    private function accountOptions(Request $request): array
    {
        if (! $request->user()->hasPermission('user.view')) {
            return [];
        }

        return $this->compiler
            ->apply(User::query()->active(), $request->user(), 'user.view')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function typeOptions(): array
    {
        return ProjectType::query()->active()->orderBy('sort')->orderBy('name')
            ->pluck('name', 'id')->all();
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

    /**
     * @return array<string,string>
     */
    private function viewOptions(): array
    {
        return [
            'active' => 'Active projects',
            'mine' => 'Mine',
            'overdue' => 'Past their planned end',
            'archived' => 'Archived',
            'all' => 'Everything you can see',
        ];
    }
}
