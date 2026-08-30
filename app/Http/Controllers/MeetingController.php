<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\MeetingRequest;
use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Policies\MeetingPolicy;
use App\Services\MeetingService;
use App\Support\Enums\Confidentiality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Meetings (§19).
 *
 * Four separate authorities, which is why this controller has more endpoints than the other two in
 * the group and why none of them are folded into `update`:
 *
 *   ARRANGING    `meeting.edit`            — time, place, agenda. Frozen once minutes circulate.
 *   INVITING     `meeting.edit` + candidate — a grant: a participant row confers reach.
 *   RECORDING    `meeting.record_minutes`  — writing what happened. A different person, usually.
 *   CIRCULATING  `meeting.circulate`       — releasing it. The act that makes minutes readable.
 *
 * The separation is not ceremony. A project administrator books the room; the secretary writes the
 * minutes; the chair releases them. Collapsing recording into editing would let anyone who can move
 * a meeting rewrite the record of what was decided at it.
 *
 * Minutes are the one FIELD-level rule in the application. Meeting::minutesVisibleTo() withholds an
 * uncirculated draft from the room while leaving the meeting itself visible, because hiding it in
 * the scope compiler would remove the whole appointment from people who are entitled to know it is
 * happening. Every view of the minutes goes through the `viewMinutes` policy question, never through
 * `view` alone.
 *
 * Action items are Tasks. `raiseAction` stamps the morph link server-side and hands off to
 * TaskService, so an action lands on the assignee's list, in their notifications, on the calendar
 * and in the overdue report — none of which would look inside a private meeting_actions table (§90).
 */
class MeetingController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly MeetingService $meetings,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Meeting::class);

        $user = $request->user();

        $query = $this->compiler->apply(Meeting::query(), $user, 'meeting.view')
            ->with([
                // `key` because the row renders isCancelled(), which reads it.
                'status:id,key,label,color,is_terminal',
                'project:id,name,number',
                'orgUnit:id,name',
                'organizer:id,name',
            ])
            ->withCount([
                'participants',
                'participants as attended_count' => fn ($q) => $q->where('attended', true),
                'decisions',
            ]);

        $query->search((string) $request->query('q', ''));

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        $view = (string) $request->query('view', 'upcoming');

        match ($view) {
            'upcoming' => $query->upcoming(),
            'mine' => $query->where(function ($q) use ($user) {
                $q->where('organizer_id', $user->id)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id));
            }),
            'past' => $query->where('starts_at', '<', now()),
            'awaiting_minutes' => $query->where('starts_at', '<', now())->whereNull('minutes_circulated_at'),
            'all' => $query,
            default => $query->upcoming(),
        };

        /** @var LengthAwarePaginator $meetings */
        $meetings = $query
            ->orderBy('starts_at', $view === 'upcoming' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('meetings.index', [
            'meetings' => $meetings,
            'statuses' => $this->statuses->optionsFor('meeting'),
            'projects' => $this->projectOptions($request),
            'views' => $this->viewOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'project' => $request->query('project'),
                'status' => $request->query('status'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Meeting::class),
        ]);
    }

    public function show(Request $request, Meeting $meeting): View
    {
        $this->authorize('view', $meeting);

        $user = $request->user();

        $meeting->loadMissing([
            'status', 'project', 'orgUnit', 'organizer', 'creator', 'minutesRecorder',
            'participants.user:id,name',
            'decisions.owner:id,name',
        ]);

        $canViewMinutes = $user->can('viewMinutes', $meeting);

        // Actions are re-filtered rather than merely loaded. Seeing a meeting is not seeing every
        // task raised at it: an action assigned into a department the actor cannot reach must be
        // counted, never named (§61).
        $actions = $this->compiler
            ->apply($meeting->actions()->getQuery(), $user, 'task.view')
            ->with(['status:id,label,color,is_terminal', 'assignee:id,name'])
            ->orderBy('due_on')
            ->get();

        return view('meetings.show', [
            'meeting' => $meeting,

            // The decisions are part of the record of the meeting, so they follow the minutes'
            // visibility rather than the meeting's — a decision line IS minutes content.
            'decisions' => $canViewMinutes ? $meeting->decisions : collect(),
            'canViewMinutes' => $canViewMinutes,
            'actions' => $actions,
            'hiddenActionCount' => $meeting->actions()->count() - $actions->count(),
            'timeline' => $this->activity->timelineFor($meeting)->limit(25)->get(),
            'transitions' => $this->statuses->available('meeting', $meeting->status_id, $user),
            'candidates' => $this->candidateOptions($request, $meeting),
            'ownParticipation' => $this->ownParticipation($meeting, $user),
            'roles' => MeetingService::roleOptions(),
            'canEdit' => $user->can('update', $meeting),
            'canInvite' => $user->can('invite', [$meeting, null]),
            'canRecordMinutes' => $user->can('recordMinutes', $meeting),
            'canCirculate' => $user->can('circulate', $meeting),
            'canCancel' => $user->can('cancel', $meeting),
            'canDelete' => $user->can('delete', $meeting),
            'canRaiseAction' => $user->can('create', Task::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Meeting::class);

        $meeting = new Meeting(['confidentiality' => Confidentiality::Internal]);

        if ($projectId = (int) $request->query('project', 0)) {
            $project = Project::find($projectId);

            if ($project !== null && $request->user()->can('view', $project)) {
                $meeting->project_id = $project->getKey();
                $meeting->org_unit_id = $project->org_unit_id;
            }
        }

        return view('meetings.create', [
            'meeting' => $meeting,
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(MeetingRequest $request): RedirectResponse
    {
        $this->authorize('create', Meeting::class);

        $user = $request->user();
        $data = $request->meetingAttributes();

        $this->assertPlacementIsReachable($request, $data);

        $meeting = $this->meetings->create($data, $user);

        $participants = $this->authorizedCandidates($request, $meeting, $request->participantIds());

        if ($participants !== []) {
            $this->meetings->invite($meeting, $participants, $user, $request->participantRole());
        }

        return redirect()->route('meetings.show', $meeting)
            ->with('status', $meeting->displayName().' has been scheduled.');
    }

    public function edit(Request $request, Meeting $meeting): View
    {
        $this->authorize('update', $meeting);

        return view('meetings.edit', [
            'meeting' => $meeting,
            ...$this->formOptions($request, $meeting),
        ]);
    }

    public function update(MeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('update', $meeting);

        $data = $request->meetingAttributes();

        $this->assertPlacementIsReachable($request, $data);

        // Handing the meeting to a different organizer confers reach on that account, so it is
        // authorized as the grant it is rather than as a field edit (§84).
        if (array_key_exists('organizer_id', $data)
            && (int) ($data['organizer_id'] ?? 0) !== (int) ($meeting->organizer_id ?? 0)) {
            $organizer = $data['organizer_id'] !== null ? User::findOrFail((int) $data['organizer_id']) : null;

            $this->authorize('invite', [$meeting, $organizer]);
        }

        $this->meetings->update($meeting, $data, $request->user());

        return redirect()->route('meetings.show', $meeting)
            ->with('status', $meeting->displayName().' has been updated.');
    }

    // ------------------------------------------------------------------ people

    public function invite(Request $request, Meeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'participants' => ['required', 'array', 'max:200'],
            'participants.*' => ['integer'],
            'role' => ['nullable', 'in:chair,secretary,participant,optional'],
        ]);

        $this->authorize('invite', [$meeting, null]);

        $ids = array_values(array_unique(array_map('intval', $validated['participants'])));
        $allowed = $this->authorizedCandidates($request, $meeting, $ids);

        if ($allowed === []) {
            return back()->withErrors(['participants' => 'None of those people can be invited to this meeting.']);
        }

        $added = $this->meetings->invite($meeting, $allowed, $request->user(), $validated['role'] ?? 'participant');

        return back()->with('status', count($added).' person(s) invited.');
    }

    public function uninvite(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        abort_unless((int) $participant->meeting_id === (int) $meeting->getKey(), 404);

        $this->authorize('invite', [$meeting, null]);

        $this->meetings->uninvite($meeting, (int) $participant->user_id, $request->user());

        return back()->with('status', 'Removed from the meeting.');
    }

    /**
     * Send apologies for your own invitation.
     *
     * Authorized against the participant row, not the meeting, because the question is "is this
     * yours" rather than "may you manage this" — MeetingParticipantPolicy::respond() refuses
     * somebody else's row outright (§84).
     */
    public function respond(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        abort_unless((int) $participant->meeting_id === (int) $meeting->getKey(), 404);

        $this->authorize('respond', $participant);

        $validated = $request->validate([
            'apologies' => ['nullable', 'string', 'max:300'],
        ]);

        $this->meetings->respond($participant, $validated['apologies'] ?? null, $request->user());

        return back()->with('status', 'Your apologies have been recorded.');
    }

    /**
     * Take the register.
     *
     * Part of recording what happened rather than of arranging it, so it borrows
     * `meeting.record_minutes` — and the service loads the rows through the meeting's own relation,
     * so a participant id from another meeting simply is not found.
     */
    public function attendance(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('recordMinutes', $meeting);

        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*' => ['boolean'],
        ]);

        $map = [];

        foreach ($validated['attendance'] as $id => $present) {
            $map[(int) $id] = (bool) $present;
        }

        $this->meetings->recordAttendance($meeting, $map, $request->user());

        return back()->with('status', 'Attendance recorded.');
    }

    // ------------------------------------------------------------------ the record

    public function minutes(Request $request, Meeting $meeting): View
    {
        $this->authorize('recordMinutes', $meeting);

        $meeting->loadMissing(['participants.user:id,name', 'decisions.owner:id,name']);

        return view('meetings.minutes', [
            'meeting' => $meeting,
            'people' => $this->candidateOptions($request, $meeting, includeExisting: true),
        ]);
    }

    public function storeMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('recordMinutes', $meeting);

        $validated = $request->validate([
            'minutes' => ['nullable', 'string', 'max:50000'],
        ]);

        $this->meetings->recordMinutes($meeting, $validated['minutes'] ?? null, $request->user());

        return redirect()->route('meetings.minutes', $meeting)
            ->with('status', 'Minutes saved. They stay unpublished until you circulate them.');
    }

    public function storeDecision(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('create', [MeetingDecision::class, $meeting]);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'max:500'],
            'rationale' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        // An owner is a person named in the record of the meeting, so the same candidate test the
        // invitation list uses applies — you may not attribute a decision to somebody in another
        // company, or to a disabled account (§75).
        if (($ownerId = $validated['owner_id'] ?? null) !== null) {
            $owner = User::findOrFail((int) $ownerId);

            $this->authorize('invite', [$meeting, $owner]);
        }

        $this->meetings->addDecision($meeting, $validated, $request->user());

        return back()->with('status', 'Decision recorded.');
    }

    /**
     * Raise an action item from the meeting.
     *
     * The link is stamped in the service, never accepted from the body. Authorization is asked
     * twice on purpose: `recordMinutes` because an action is part of the record of what happened,
     * and `create` on Task because it puts real work on somebody — an account that may minute a
     * meeting is not thereby an account that may open tasks.
     */
    public function raiseAction(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('recordMinutes', $meeting);
        $this->authorize('create', Task::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'integer'],
            'due_on' => ['nullable', 'date'],
        ]);

        $assignee = ($validated['assignee_id'] ?? null) !== null
            ? User::findOrFail((int) $validated['assignee_id'])
            : null;

        if ($assignee !== null) {
            // Built unsaved so TaskPolicy sees the real project, unit and confidentiality the
            // action will be created with, exactly as TaskController::store() does.
            $draft = new Task([
                'project_id' => $meeting->project_id,
                'org_unit_id' => $meeting->org_unit_id,
                'confidentiality' => $meeting->confidentiality,
            ]);
            $draft->organization_id = $meeting->organization_id;

            $this->authorize('assign', [$draft, $assignee]);
        }

        $task = $this->meetings->raiseAction($meeting, [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assignee_id' => $assignee?->getKey(),
            'due_on' => $validated['due_on'] ?? null,
            'confidentiality' => $meeting->confidentiality,
            'priority' => 'normal',
        ], $request->user());

        return back()->with('status', $task->displayName().' has been raised from this meeting.');
    }

    /**
     * Move the meeting: hold it, circulate the minutes, reschedule it or cancel it.
     *
     * Which of those a given status id means is not decided here and is not decided by a status
     * key — MeetingService resolves the configured whitelist row and branches on its permission, so
     * a tenant that renames "Minuted" to "Closed" keeps working minutes and a working cancellation.
     * This method's job is to ask the policy again with the actor who was shown the buttons (§84).
     */
    public function transition(Request $request, Meeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $to = Status::query()->forEntity('meeting')->whereKey($validated['status_id'])->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status a meeting can be in.']);
        }

        $row = $this->statuses->find(
            'meeting',
            $meeting->status_id !== null ? (int) $meeting->status_id : null,
            (int) $to->getKey(),
        );

        // The same discriminator the service uses, asked as a policy question here: circulation is
        // `circulate`, a terminal move that is not circulation is `cancel`, anything else is an
        // ordinary edit of the arrangements.
        $ability = match (true) {
            $row?->permission_key === 'meeting.circulate' => 'circulate',
            (bool) $to->is_terminal => 'cancel',
            default => 'update',
        };

        $this->authorize($ability, $meeting);

        try {
            $this->meetings->transition($meeting, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return redirect()->route('meetings.show', $meeting)
            ->with('status', $meeting->displayName().' is now '.$to->label.'.');
    }

    public function destroy(Request $request, Meeting $meeting): RedirectResponse
    {
        // MeetingPolicy refuses this outright once minutes or decisions exist: a meeting that
        // produced a record is cancelled, never erased (§59).
        $this->authorize('delete', $meeting);

        $name = $meeting->displayName();

        $this->activity->record(
            $meeting,
            'deleted',
            $request->user()->name.' deleted '.$name,
            [],
            $request->user(),
            $meeting->project_id !== null ? (int) $meeting->project_id : null,
        );

        $meeting->delete();

        return redirect()->route('meetings.index')->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function authorizedCandidates(Request $request, Meeting $meeting, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $user = $request->user();
        $policy = app(MeetingPolicy::class);

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (User $candidate) => $policy->invite($user, $meeting, $candidate))
            ->map(fn (User $candidate) => (int) $candidate->getKey())
            ->values()
            ->all();
    }

    private function ownParticipation(Meeting $meeting, User $user): ?MeetingParticipant
    {
        return $meeting->participants->firstWhere('user_id', $user->getKey());
    }

    /**
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
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Meeting $meeting): array
    {
        return [
            'projects' => $this->projectOptions($request),
            'units' => $this->unitOptions($request),
            'people' => $this->candidateOptions($request, $meeting, includeExisting: $meeting === null),
            'confidentialities' => Confidentiality::options(),
            'roles' => MeetingService::roleOptions(),
            'nextNumber' => $meeting === null ? $this->numbers->preview('meeting') : null,
        ];
    }

    /**
     * People this actor may put on this meeting.
     *
     * Asked of the policy one candidate at a time rather than reimplemented in SQL, so the picker
     * never offers a name the save would refuse and never doubles as a staff directory (§61).
     *
     * @return array<int|string,string>
     */
    private function candidateOptions(Request $request, ?Meeting $meeting, bool $includeExisting = false): array
    {
        $user = $request->user();

        if (! $user->hasPermission('user.view')) {
            return [];
        }

        $policy = app(MeetingPolicy::class);
        $subject = $meeting ?? tap(new Meeting, function (Meeting $m) use ($user) {
            $m->organization_id = $user->organization_id;
            $m->confidentiality = Confidentiality::Internal;
        });

        $already = ($meeting !== null && ! $includeExisting)
            ? $meeting->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return $this->compiler
            ->apply(User::query()->active(), $user, 'user.view')
            ->when($already !== [], fn ($q) => $q->whereNotIn('id', $already))
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->filter(fn (User $candidate) => $policy->invite($user, $subject, $candidate))
            ->mapWithKeys(fn (User $candidate) => [$candidate->id => $candidate->name])
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
    private function viewOptions(): array
    {
        return [
            'upcoming' => 'Upcoming',
            'mine' => 'Mine',
            'past' => 'Past',
            'awaiting_minutes' => 'Awaiting minutes',
            'all' => 'Everything you can see',
        ];
    }
}
