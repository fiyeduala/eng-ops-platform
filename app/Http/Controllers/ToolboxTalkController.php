<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ToolboxTalkRequest;
use App\Models\Document;
use App\Models\ToolboxTalk;
use App\Models\ToolboxTalkAttendee;
use App\Models\User;
use App\Services\ToolboxTalkService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The five-minute briefing, and the list of who was actually there.
 *
 * THE ATTENDANCE LIST IS THE RECORD. The topic and the content are the easy half; what an inspector
 * asks for is the sheet with names on it, and what a company needs eighteen months later — when the
 * person who was briefed says nobody told them — is that same sheet. So attendance is not a field
 * on the talk's edit form: it arrives through `record()`, together with the moment the talk was
 * actually held, and it is the act that moves the record to `held`.
 *
 * ATTENDEES ARE NOT ALL USERS. `person_name` carries subcontractors, agency labour and visitors who
 * have no account and never will, and a register that could only record employees would be missing
 * exactly the people most likely to be hurt. `user_id` is optional and linking one is a convenience,
 * not a requirement.
 *
 * A SIGNATURE IS THE ATTENDEE'S OWN ACT. `sign()` is a separate endpoint behind its own ability
 * because somebody confirming they attended is making a statement about themselves — a supervisor
 * ticking the whole list on their behalf is the thing the signature exists to rule out.
 */
class ToolboxTalkController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ToolboxTalkService $talks,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'toolbox_talk';
    }

    protected function modelClass(): string
    {
        return ToolboxTalk::class;
    }

    protected function routePrefix(): string
    {
        return 'toolbox-talks';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ToolboxTalk::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'presenter:id,name',
        ])->withCount('attendees');

        $this->applyCommonFilters($query, $request);

        $view = (string) $request->query('view', 'planned');

        match ($view) {
            'held' => $query->whereNotNull('held_at'),
            'recent' => $query->heldBetween(now()->subDays(30), now()),
            'mine' => $query->where(fn ($q) => $q->where('presented_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->whereNull('held_at'),
        };

        /** @var LengthAwarePaginator $talks */
        $talks = $query
            ->orderByRaw('CASE WHEN held_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('held_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('toolbox-talks.index', [
            'talks' => $talks,
            'statuses' => $this->statuses->optionsFor('toolbox_talk'),
            'projects' => $this->projectOptions($user),
            'views' => [
                'planned' => 'Planned',
                'recent' => 'Held in the last 30 days',
                'held' => 'Held',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => $this->commonFilters($request, 'planned'),
            'canCreate' => $user->can('create', ToolboxTalk::class),
        ]);
    }

    public function show(Request $request, ToolboxTalk $toolboxTalk): View
    {
        $this->authorize('view', $toolboxTalk);

        $user = $request->user();

        $toolboxTalk->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'presenter:id,name', 'creator:id,name', 'document:id,number,title',
            'attendees.user:id,name',
        ]);

        // The signature button is offered against THIS person's own attendance row and no other.
        // Whether it appears at all is the policy's answer, not a template's guess.
        $mine = $toolboxTalk->attendees->firstWhere('user_id', $user->id);

        return view('toolbox-talks.show', [
            'talk' => $toolboxTalk,
            'myAttendance' => $mine,
            'canSignMine' => $mine !== null && $user->can('sign', $mine),
            'timeline' => $this->activity->timelineFor($toolboxTalk)->limit(25)->get(),
            'transitions' => $this->statuses->available('toolbox_talk', $toolboxTalk->status_id, $user),
            'workflowInstance' => $toolboxTalk->currentWorkflowInstance(),
            'people' => $this->userOptions($user),
            'canEdit' => $user->can('update', $toolboxTalk),
            'canDelete' => $user->can('delete', $toolboxTalk),
            'canRecord' => $user->can('record', $toolboxTalk),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ToolboxTalk::class);

        $user = $request->user();

        $talk = new ToolboxTalk([
            'confidentiality' => Confidentiality::Internal->value,
            'project_id' => (int) $request->query('project', 0) ?: null,
            'presented_by' => $user->id,
            'duration_minutes' => 15,
        ]);

        return view('toolbox-talks.create', [
            'talk' => $talk,
            ...$this->formOptions($request, $talk),
        ]);
    }

    public function store(ToolboxTalkRequest $request): RedirectResponse
    {
        $this->authorize('create', ToolboxTalk::class);

        $data = $request->talkAttributes();

        $this->assertPlacementIsReachable($data);

        $talk = $this->talks->create($data, $request->user());

        return redirect($this->showRoute($talk))
            ->with('status', $talk->displayName().' has been planned.');
    }

    public function edit(Request $request, ToolboxTalk $toolboxTalk): View
    {
        $this->authorize('update', $toolboxTalk);

        return view('toolbox-talks.edit', [
            'talk' => $toolboxTalk,
            ...$this->formOptions($request, $toolboxTalk),
        ]);
    }

    public function update(ToolboxTalkRequest $request, ToolboxTalk $toolboxTalk): RedirectResponse
    {
        $this->authorize('update', $toolboxTalk);

        $data = $request->talkAttributes();

        $this->assertPlacementIsReachable($data);

        $this->talks->update($toolboxTalk, $data, $request->user());

        return redirect($this->showRoute($toolboxTalk))
            ->with('status', $toolboxTalk->displayName().' has been updated.');
    }

    public function destroy(Request $request, ToolboxTalk $toolboxTalk): RedirectResponse
    {
        $this->authorize('delete', $toolboxTalk);

        $name = $toolboxTalk->displayName();

        $this->recordDeletion($toolboxTalk, $request->user(), 'toolbox talk');

        $toolboxTalk->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ delivery

    /**
     * Record that the talk was given, and to whom.
     *
     * `held_at` IS ACCEPTED RATHER THAN STAMPED. The sheet is typed up at the end of the shift, not
     * at 6:40 in the morning when the talk was actually given, and a register that insisted on the
     * moment of data entry would record a time everybody knows is wrong. The service defaults it to
     * now when it is left blank.
     *
     * The attendee rows replace whatever was there; the service is the only thing that writes them,
     * and it is what decides that a linked `user_id` must belong to this tenant.
     */
    public function record(Request $request, ToolboxTalk $toolboxTalk): RedirectResponse
    {
        $this->authorize('record', $toolboxTalk);

        $validated = $request->validate([
            'held_at' => ['nullable', 'date'],
            'attendees' => ['nullable', 'array', 'max:300'],
            'attendees.*.user_id' => ['nullable', 'integer'],
            'attendees.*.person_name' => ['nullable', 'string', 'max:200'],
            'attendees.*.company' => ['nullable', 'string', 'max:200'],
            'attendees.*.trade' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->talks->record(
                $toolboxTalk,
                array_values($validated['attendees'] ?? []),
                $request->user(),
                $validated['held_at'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['attendees' => $e->getMessage()]);
        }

        return back()->with('status', $toolboxTalk->displayName().' has been recorded as delivered.');
    }

    /**
     * Confirm you were there.
     *
     * The attendance row is bound from the nested route and checked against the talk in the URL, so
     * `/toolbox-talks/3/attendees/91` cannot sign a row belonging to talk 12. The policy then
     * decides whether THIS actor may sign THAT row — which, for an ordinary user, means their own
     * and nobody else's (§84, §94).
     */
    public function sign(Request $request, ToolboxTalk $toolboxTalk, ToolboxTalkAttendee $attendee): RedirectResponse
    {
        abort_unless((int) $attendee->toolbox_talk_id === (int) $toolboxTalk->getKey(), 404);

        $this->authorize('sign', $attendee);

        try {
            $this->talks->sign($attendee, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['sign' => $e->getMessage()]);
        }

        return back()->with('status', 'Your attendance at '.$toolboxTalk->displayName().' has been signed.');
    }

    public function transition(Request $request, ToolboxTalk $toolboxTalk): RedirectResponse
    {
        return $this->moveStatus($request, $toolboxTalk);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request, ?ToolboxTalk $talk = null): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'handouts' => $this->handoutOptions($request->user(), $talk),
            'nextNumber' => $this->numbers->preview('toolbox_talk'),
        ];
    }

    /**
     * The documents this person could attach as the handout.
     *
     * SCOPED THROUGH `document.view` LIKE ANY OTHER DOCUMENT LIST, because a picker is a list of
     * what exists — offering titles and numbers somebody cannot open tells them about records they
     * were never meant to know of (§61). The request re-validates the chosen id against the tenant
     * regardless, so nothing here is the authorization.
     *
     * @return array<int,string>
     */
    private function handoutOptions(User $user, ?ToolboxTalk $talk): array
    {
        $query = $this->compiler->apply(Document::query(), $user, 'document.view');

        // Narrowed to the talk's own project when it has one: a briefing sheet is nearly always
        // filed against the job it was given on, and an unfiltered list is two hundred rows deep.
        if ($talk?->project_id !== null) {
            $query->where('project_id', $talk->project_id);
        }

        return $query->orderByDesc('id')->limit(200)->get()
            ->mapWithKeys(fn (Document $document) => [$document->id => $document->displayName()])
            ->all();
    }
}
