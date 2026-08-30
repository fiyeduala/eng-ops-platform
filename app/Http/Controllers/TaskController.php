<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\TaskAssignmentRequest;
use App\Http\Requests\TaskRequest;
use App\Models\Follower;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\TaskService;
use App\Support\Enums\Confidentiality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tasks (§16, §88).
 *
 * The list is one ScopeCompiler call, and it returns the union that makes this a system rather
 * than a set of modules (§90):
 *
 *   - the tasks assigned to me                                (assignee axis)
 *   - the tasks in the units I reach on the org ladder        (organizational axis)
 *   - the tasks on the projects I am a member of              (project axis)
 *
 * A seconded consultant sees the mechanical project's tasks and nothing else mechanical; a
 * department head sees their department's tasks on every project; neither is a branch in this
 * file.
 *
 * Four writes are deliberately not `update`:
 *
 *   - ASSIGNMENT is a grant, because `task.view:assigned` exists. It has its own endpoint, its own
 *     two-question policy, and it re-resolves the candidate from the POST body (§84).
 *   - STATUS moves through the configured whitelist, re-asked here with the same actor that drew
 *     the buttons.
 *   - FOLLOWING subscribes the actor and only the actor.
 *   - DELETE is refused by the policy for any task with subtasks or dependents; those are
 *     cancelled, so the history survives (§59).
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly TaskService $tasks,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $user = $request->user();

        $query = $this->compiler->apply(Task::query(), $user, 'task.view')
            ->with([
                'status:id,label,color,is_terminal',
                'project:id,name,number',
                'orgUnit:id,name',
                'assignee:id,name',
            ])
            ->withCount([
                'checklistItems',
                'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_done', true),
            ]);

        $query->search((string) $request->query('q', ''));

        if ($projectId = (int) $request->query('project', 0)) {
            // Filtering by a project the actor cannot see must narrow the list to nothing rather
            // than being ignored — silently dropping the filter would show them a wider list than
            // they asked for and let them infer the project exists.
            $query->where('project_id', $projectId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        if ($priority = (string) $request->query('priority', '')) {
            $query->where('priority', $priority);
        }

        if ($unitId = (int) $request->query('unit', 0)) {
            $unit = OrgUnit::find($unitId);

            if ($unit !== null && $user->can('view', $unit)) {
                $query->whereIn('org_unit_id', $unit->subtree()->select('id'));
            }
        }

        $view = (string) $request->query('view', 'mine');

        match ($view) {
            'mine' => $query->where('assignee_id', $user->id)->open(),
            'raised' => $query->where('created_by', $user->id),
            'overdue' => $query->open()
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', now())
                ->whereHas('status', fn ($s) => $s->where('is_terminal', false)),
            'unassigned' => $query->whereNull('assignee_id')->open(),
            'closed' => $query->whereHas('status', fn ($s) => $s->where('is_terminal', true)),
            'all' => $query,
            default => $query->open(),
        };

        /** @var LengthAwarePaginator $tasks */
        $tasks = $query
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('tasks.index', [
            'tasks' => $tasks,
            'statuses' => $this->statuses->optionsFor('task'),
            'projects' => $this->projectOptions($request),
            'units' => $this->unitOptions($request),
            'priorities' => $this->priorityOptions(),
            'views' => $this->viewOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'project' => $request->query('project'),
                'status' => $request->query('status'),
                'priority' => $request->query('priority'),
                'unit' => $request->query('unit'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Task::class),
        ]);
    }

    public function show(Request $request, Task $task): View
    {
        $this->authorize('view', $task);

        $user = $request->user();

        $task->loadMissing([
            'status', 'project', 'parent', 'orgUnit', 'assignee', 'assignedBy', 'creator', 'completedBy',
            'checklistItems.doneBy:id,name',
        ]);

        // Dependencies and subtasks are re-filtered, not merely loaded. Seeing a task is not seeing
        // every task around it: a blocker on a confidential project must appear as a constraint,
        // never as a title and a number the actor has no right to (§61).
        $dependencies = $this->compiler
            ->apply($task->dependencies()->getQuery(), $user, 'task.view')
            ->with(['dependsOn.status:id,label,color,is_terminal'])
            ->get();

        $children = $this->compiler
            ->apply($task->children()->getQuery(), $user, 'task.view')
            ->with(['status:id,label,color', 'assignee:id,name'])
            ->orderBy('id')
            ->get();

        return view('tasks.show', [
            'task' => $task,
            'dependencies' => $dependencies,
            'hiddenDependencyCount' => $task->dependencies()->count() - $dependencies->count(),
            'children' => $children,
            'timeline' => $this->activity->timelineFor($task)->limit(25)->get(),
            'transitions' => $this->statuses->available('task', $task->status_id, $user),
            'blocking' => $task->blockingDependencies(),
            'isFollowing' => $this->isFollowing($task, $user),
            'candidates' => $this->candidateOptions($request, $task),
            'blockers' => $this->blockerOptions($request, $task),
            'canEdit' => $user->can('update', $task),
            'canManage' => $user->can('manage', $task),
            'canAssign' => $user->can('assign', [$task, null]),
            'canDelete' => $user->can('delete', $task),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Task::class);

        $task = new Task([
            'confidentiality' => Confidentiality::Internal,
            'priority' => 'normal',
        ]);

        // Pre-filling from ?project= is a convenience; the value still goes through
        // assertPlacementIsReachable() on the way back in.
        if ($projectId = (int) $request->query('project', 0)) {
            $project = Project::find($projectId);

            if ($project !== null && $request->user()->can('view', $project)) {
                $task->project_id = $project->getKey();
                $task->org_unit_id = $project->org_unit_id;
            }
        }

        return view('tasks.create', [
            'task' => $task,
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $user = $request->user();
        $data = $request->taskAttributes();

        $this->assertPlacementIsReachable($request, $request->validated());

        // The assignee arrives as a plain integer in the body. Writing it grants that account sight
        // of the task, so it is authorized as a grant against the task that is about to exist —
        // built unsaved, so the policy sees the real project, unit and confidentiality (§84).
        $assignee = $this->resolveAssignee($request->validated());

        if ($assignee !== null) {
            $draft = new Task($data);
            $draft->organization_id = $user->organization_id;

            $this->authorize('assign', [$draft, $assignee]);

            $data['assignee_id'] = $assignee->getKey();
        }

        $task = $this->tasks->create($data, $user);

        return redirect()->route('tasks.show', $task)
            ->with('status', $task->displayName().' has been raised.');
    }

    public function edit(Request $request, Task $task): View
    {
        $this->authorize('update', $task);

        return view('tasks.edit', [
            'task' => $task,
            ...$this->formOptions($request, $task),
        ]);
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $data = $request->taskAttributes();

        $this->assertPlacementIsReachable($request, $data);

        // Moving a task between projects re-shapes who can see it, which is a management act
        // rather than an edit. A person with `task.edit:assigned` may correct their own task's
        // description; they may not move it somewhere their reach is wider.
        if (array_key_exists('project_id', $data)
            && (int) ($data['project_id'] ?? 0) !== (int) ($task->project_id ?? 0)) {
            $this->authorize('manage', $task);
        }

        try {
            $this->tasks->update($task, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['parent_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tasks.show', $task)
            ->with('status', $task->displayName().' has been updated.');
    }

    /**
     * Assign, reassign or unassign.
     *
     * One endpoint because it is one decision from the user's point of view, and two policy
     * questions because taking work off a named person is not the same authority as scheduling an
     * unassigned task. The policy picks which to ask from the task's current state, not from
     * anything the request says.
     */
    public function assign(TaskAssignmentRequest $request, Task $task): RedirectResponse
    {
        $candidate = $this->resolveAssignee($request->validated());

        $this->authorize('reassign', [$task, $candidate]);

        $this->tasks->assign($task, $candidate, $request->user());

        return redirect()->route('tasks.show', $task)->with(
            'status',
            $candidate === null
                ? $task->displayName().' is now unassigned.'
                : $task->displayName().' is now with '.$candidate->name.'.',
        );
    }

    /**
     * Move the task to another status.
     *
     * The buttons come from StatusMachine::available(); this asks StatusMachine again with the
     * same actor, because a button that was not drawn is not a button that cannot be pressed (§84).
     * The service adds the one rule the state machine cannot know: a task may not close while
     * something it waits on is still open.
     */
    public function transition(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // Through Eloquent, so the tenant scope applies: a status id from another company must not
        // resolve at all, let alone be assignable.
        $to = Status::query()->forEntity('task')->whereKey($validated['status_id'])->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status a task can be in.']);
        }

        try {
            $this->tasks->transition($task, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return redirect()->route('tasks.show', $task)
            ->with('status', $task->displayName().' is now '.$to->label.'.');
    }

    /**
     * Follow this task.
     *
     * Lives here rather than on a generic polymorphic controller on purpose. A route that accepted
     * `followable_type` from a request body would be an arbitrary-class surface — every model in
     * the application reachable by name from one endpoint — for a feature that grants nothing.
     * When a second followable model lands in Phase 3 it gets its own two lines, which is cheaper
     * than the audit that surface would need.
     */
    public function follow(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('follow', [Follower::class, $task]);

        $this->tasks->subscribe($task, (int) $request->user()->id, 'manual', $request->user());

        return back()->with('status', 'You will be notified about '.$task->number.'.');
    }

    public function unfollow(Request $request, Task $task): RedirectResponse
    {
        // No authorization beyond being signed in: unfollowing only ever removes the actor's own
        // row, and refusing it would leave people permanently subscribed to records they can no
        // longer open.
        $this->tasks->unsubscribe($task, (int) $request->user()->id);

        return back()->with('status', 'You will no longer be notified about '.$task->number.'.');
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $name = $task->displayName();
        $projectId = $task->project_id !== null ? (int) $task->project_id : null;

        $this->activity->record(
            $task,
            'deleted',
            $request->user()->name.' deleted '.$name,
            [],
            $request->user(),
            $projectId,
        );

        $task->delete();

        return redirect()->route('tasks.index')
            ->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The candidate named in the body, resolved through Eloquent so the tenant scope applies.
     *
     * @param  array<string,mixed>  $data
     */
    private function resolveAssignee(array $data): ?User
    {
        $id = $data['assignee_id'] ?? null;

        return $id === null ? null : User::findOrFail((int) $id);
    }

    /**
     * Every foreign key on the way in, re-authorized against the actor.
     *
     * The form only offers reachable options; the form is not the boundary. Filing a task into a
     * project or a department the actor cannot see would move it out of their own reach — and,
     * worse, into somebody else's — on nothing more than a typed id (§84).
     *
     * @param  array<string,mixed>  $data
     */
    private function assertPlacementIsReachable(Request $request, array $data): void
    {
        if (($projectId = $data['project_id'] ?? null) !== null) {
            $this->authorize('view', Project::findOrFail($projectId));
        }

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }

        // A parent you cannot see is a parent you cannot pick: otherwise the breadcrumb on the
        // child would render a title from a record the actor has no right to.
        if (($parentId = $data['parent_id'] ?? null) !== null) {
            $this->authorize('view', Task::findOrFail($parentId));
        }
    }

    private function isFollowing(Task $task, User $user): bool
    {
        return Follower::query()
            ->where('followable_type', $task->getMorphClass())
            ->where('followable_id', $task->getKey())
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Task $task): array
    {
        return [
            'projects' => $this->projectOptions($request),
            'units' => $this->unitOptions($request),
            'people' => $task === null ? $this->candidateOptions($request, null) : [],
            'confidentialities' => Confidentiality::options(),
            'priorities' => $this->priorityOptions(),
            'parents' => $this->parentOptions($request, $task),

            // A preview only. The number is claimed in TaskService, at save.
            'nextNumber' => $task === null ? $this->numbers->preview('task') : null,
        ];
    }

    /**
     * People this actor may actually put on this task.
     *
     * Built by asking the policy about each candidate rather than by reimplementing its rules in
     * SQL, so the picker never offers a name the save would refuse — and never becomes a staff
     * directory for someone whose access says otherwise (§61). Capped at 500.
     *
     * @return array<int|string,string>
     */
    private function candidateOptions(Request $request, ?Task $task): array
    {
        $user = $request->user();

        if (! $user->hasPermission('user.view')) {
            return [];
        }

        $policy = app(TaskPolicy::class);
        $subject = $task ?? tap(new Task, fn (Task $t) => $t->organization_id = $user->organization_id);

        return $this->compiler
            ->apply(User::query()->active(), $user, 'user.view')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->filter(fn (User $candidate) => $policy->assign($user, $subject, $candidate))
            ->mapWithKeys(fn (User $candidate) => [$candidate->id => $candidate->name])
            ->all();
    }

    /**
     * Tasks this one may be made a subtask of: reachable, not itself, and not one of its own
     * descendants. The descendant check is the service's to enforce properly — this only keeps the
     * obvious traps out of the dropdown.
     *
     * @return array<int|string,string>
     */
    private function parentOptions(Request $request, ?Task $task): array
    {
        $query = $this->compiler->apply(Task::query(), $request->user(), 'task.view');

        if ($task !== null) {
            $query->whereKeyNot($task->getKey())->where(function ($q) use ($task) {
                $q->whereNull('parent_id')->orWhere('parent_id', '!=', $task->getKey());
            });
        }

        return $query->orderByDesc('id')->limit(200)->get()
            ->mapWithKeys(fn (Task $t) => [$t->id => $t->displayName()])
            ->all();
    }

    /**
     * Tasks this one may be told to wait for.
     *
     * Scope-compiled like everything else, so the picker is not a directory of task numbers: a
     * blocker you cannot open is a blocker you cannot name, and the controller asks the policy
     * again on the way back in anyway (§61, §84). Existing edges are dropped so the list does not
     * offer a duplicate the service would refuse.
     *
     * @return array<int|string,string>
     */
    private function blockerOptions(Request $request, Task $task): array
    {
        $already = $task->dependencies()->pluck('depends_on_task_id')->all();

        return $this->compiler
            ->apply(Task::query(), $request->user(), 'task.view')
            ->whereKeyNot($task->getKey())
            ->when($already !== [], fn ($q) => $q->whereNotIn('id', $already))
            ->orderByDesc('id')->limit(200)->get()
            ->mapWithKeys(fn (Task $t) => [$t->id => $t->displayName()])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function projectOptions(Request $request): array
    {
        return $this->compiler
            ->apply(Project::query()->active(), $request->user(), 'project.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => $p->displayName()])
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

    /**
     * @return array<string,string>
     */
    private function priorityOptions(): array
    {
        return collect(TaskRequest::priorities())
            ->mapWithKeys(fn (string $p) => [$p => ucfirst($p)])->all();
    }

    /**
     * @return array<string,string>
     */
    private function viewOptions(): array
    {
        return [
            'mine' => 'Assigned to me',
            'raised' => 'Raised by me',
            'overdue' => 'Overdue',
            'unassigned' => 'Unassigned',
            'open' => 'All open',
            'closed' => 'Closed',
            'all' => 'Everything you can see',
        ];
    }
}
