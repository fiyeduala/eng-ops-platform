<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\IncidentPersonRequest;
use App\Http\Requests\IncidentRequest;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Services\IncidentService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Something happened, and the record of it starts the moment somebody says so (§27).
 *
 * THERE IS NO DRAFT AND NO SUBMIT STEP. Every other register in this phase has one; this one does
 * not, because an incident sitting in somebody's drafts is an incident nobody has been told about.
 * `store()` reports it. The gap between `occurred_at` and `reported_at` is a number safety
 * regulators ask for by name, and this controller is the reason it can be trusted.
 *
 * MEDICAL DETAIL IS GATED SEPARATELY FROM THE INCIDENT. A supervisor who can read the incident is
 * not thereby cleared to read what the injury was, so every screen and every payload that touches
 * `incident_persons` runs through IncidentPersonPolicy — `viewMedical` for reading, `createFor` and
 * `update` for writing — and the request strips the six medical columns when that check failed. The
 * flag passed to `personAttributes()` is the RESULT of a policy call and never a request value
 * (§6, §68, §84).
 *
 * NOTHING HERE OPENS AN INVESTIGATION OR RAISES A CORRECTIVE ACTION. Those happen because an
 * administrator configured a workflow stage to do them. A company whose procedure says a near miss
 * needs no investigation simply does not add the action; one that wants two corrective actions on
 * every reportable incident configures two. Putting either in this controller would have made that
 * a code change (§32, §73, §90).
 */
class IncidentController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly IncidentService $incidents,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'hse_incident';
    }

    protected function modelClass(): string
    {
        return Incident::class;
    }

    protected function routePrefix(): string
    {
        return 'incidents';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Incident::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'reporter:id,name',
        ])->withCount(['people', 'correctiveActions']);

        $this->applyCommonFilters($query, $request);

        foreach (['category', 'severity'] as $field) {
            if ($value = (string) $request->query($field, '')) {
                $query->where($field, $value);
            }
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'reportable' => $query->reportable(),
            'investigation' => $query->awaitingInvestigation(),
            'mine' => $query->where(fn ($q) => $q->where('reported_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->open(),
        };

        /** @var LengthAwarePaginator $incidents */
        $incidents = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('incidents.index', [
            'incidents' => $incidents,
            'statuses' => $this->statuses->optionsFor('hse_incident'),
            'projects' => $this->projectOptions($user),
            'categories' => $this->lists->for('hse_incident.categories', Incident::DEFAULT_CATEGORIES),
            'severities' => $this->lists->for('hse_incident.severities', Incident::DEFAULT_SEVERITIES),
            'views' => [
                'open' => 'Open',
                'investigation' => 'Awaiting investigation',
                'reportable' => 'Reportable',
                'mine' => 'Reported by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'category' => $request->query('category'),
                'severity' => $request->query('severity'),
            ],
            'canCreate' => $user->can('create', Incident::class),
        ]);
    }

    public function show(Request $request, Incident $incident): View
    {
        $this->authorize('view', $incident);

        $user = $request->user();

        $incident->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'reporter:id,name', 'closer:id,name', 'creator:id,name',
            'people.user:id,name',
            'investigation.status', 'investigation.lead:id,name',
            'correctiveActions.status', 'correctiveActions.assignee:id,name',
        ]);

        // ONE POLICY CALL DECIDES WHETHER THE MEDICAL COLUMNS ARE RENDERED AT ALL, and it is made
        // against a person row rather than the incident, because that is what the ability is about.
        // With nobody recorded yet there is no row to ask about, so an unsaved one standing in this
        // incident's place is asked instead — the same probe `storePerson()` authorizes against. It
        // has to be asked even then, because the add-person form carries the medical fields too and
        // an empty incident is exactly when the first injury gets typed in.
        $probe = $incident->people->first();

        if ($probe === null) {
            $probe = new IncidentPerson(['incident_id' => $incident->getKey()]);
            $probe->setRelation('incident', $incident);
        }

        $canViewMedical = $user->can('viewMedical', $probe);

        return view('incidents.show', [
            'incident' => $incident,
            'timeline' => $this->activity->timelineFor($incident)->limit(25)->get(),
            'transitions' => $this->statuses->available('hse_incident', $incident->status_id, $user),
            'workflowInstance' => $incident->currentWorkflowInstance(),
            'canViewMedical' => $canViewMedical,
            'canAddPeople' => $user->can('createFor', [IncidentPerson::class, $incident]),
            'canEdit' => $user->can('update', $incident),
            'canDelete' => $user->can('delete', $incident),
            'canInvestigate' => $user->can('investigate', $incident),
            'canClose' => $user->can('close', $incident),
            'canReopen' => $user->can('reopen', $incident),

            // Asked here rather than in the view. A Blade file that resolves a policy against a
            // class name is a second place authorization gets decided, and the second place is
            // always the one that drifts (§6).
            'canRaiseAction' => $user->can('create', CorrectiveAction::class),
            'actionTypes' => $this->lists->for('corrective_action.types', CorrectiveAction::DEFAULT_TYPES),
            'actionPriorities' => $this->lists->for(
                'corrective_action.priorities',
                CorrectiveAction::DEFAULT_PRIORITIES,
            ),
            ...$this->personOptions($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Incident::class);

        return view('incidents.create', [
            // `restricted` and not `internal`. An incident names people and describes what happened
            // to them; the default that matters is the one that holds when nobody was thinking
            // about it, and at 11pm on site nobody is thinking about it.
            'incident' => new Incident([
                'confidentiality' => Confidentiality::Restricted->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'occurred_at' => now()->format('Y-m-d\TH:i'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(IncidentRequest $request): RedirectResponse
    {
        $this->authorize('create', Incident::class);

        $data = $request->incidentAttributes();

        $this->assertPlacementIsReachable($data);

        $incident = $this->incidents->create($data, $request->user());

        return redirect($this->showRoute($incident))
            ->with('status', $incident->displayName().' has been reported.');
    }

    public function edit(Request $request, Incident $incident): View
    {
        $this->authorize('update', $incident);

        return view('incidents.edit', [
            'incident' => $incident,
            ...$this->formOptions($request),
        ]);
    }

    public function update(IncidentRequest $request, Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $data = $request->incidentAttributes();

        $this->assertPlacementIsReachable($data);

        $this->incidents->update($incident, $data, $request->user());

        return redirect($this->showRoute($incident))
            ->with('status', $incident->displayName().' has been updated.');
    }

    public function destroy(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('delete', $incident);

        $name = $incident->displayName();

        $this->recordDeletion($incident, $request->user(), 'incident');

        $incident->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ people

    /**
     * Record somebody who was involved.
     *
     * TWO AUTHORIZATION QUESTIONS, NOT ONE. `createFor` asks whether this actor may add a person to
     * THIS incident; `viewMedical` asks whether the medical columns in the payload may be written at
     * all. A person cleared to note that a colleague witnessed something is not thereby cleared to
     * record their injuries, and the request drops those six fields when the second check fails
     * rather than silently accepting them (§68).
     */
    public function storePerson(IncidentPersonRequest $request, Incident $incident): RedirectResponse
    {
        $this->authorize('createFor', [IncidentPerson::class, $incident]);

        $probe = new IncidentPerson(['incident_id' => $incident->getKey()]);
        $probe->setRelation('incident', $incident);

        $data = $request->personAttributes($request->user()->can('viewMedical', $probe));

        $this->incidents->addPerson($incident, $data, $request->user());

        return back()->with('status', 'The person has been added to '.$incident->displayName().'.');
    }

    public function updatePerson(
        IncidentPersonRequest $request,
        Incident $incident,
        IncidentPerson $person,
    ): RedirectResponse {
        $this->assertBelongsTo($incident, $person);

        $this->authorize('update', $person);

        $data = $request->personAttributes($request->user()->can('viewMedical', $person));

        $this->incidents->updatePerson($person, $data, $request->user());

        return back()->with('status', 'The record has been amended.');
    }

    public function destroyPerson(Request $request, Incident $incident, IncidentPerson $person): RedirectResponse
    {
        $this->assertBelongsTo($incident, $person);

        $this->authorize('delete', $person);

        $this->incidents->removePerson($person, $request->user());

        return back()->with('status', 'The person has been removed from '.$incident->displayName().'.');
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * Flag it as reportable to the authorities.
     *
     * NO JURISDICTION IS HARD-CODED anywhere in this path. Which incidents are reportable, to whom
     * and within how many days differs by country and by industry; a company encodes its own rule as
     * a workflow condition on `is_reportable` and this endpoint carries the reference the authority
     * gave back (§47).
     */
    public function markReportable(Request $request, Incident $incident): RedirectResponse
    {
        // `investigate` rather than `update`. Telling a regulator about an incident is not the same
        // act as correcting its wording, and the people trusted with the first are a smaller set.
        $this->authorize('investigate', $incident);

        $validated = $request->validate([
            'reported_to_authority_on' => ['nullable', 'date'],
            'authority_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->incidents->markReportable(
            $incident,
            $request->user(),
            $validated['reported_to_authority_on'] ?? null,
            $validated['authority_reference'] ?? null,
        );

        return back()->with('status', $incident->displayName().' is flagged as reportable.');
    }

    public function close(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('close', $incident);

        try {
            $this->incidents->close($incident, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $incident->displayName().' has been closed.');
    }

    public function reopen(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('reopen', $incident);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->incidents->reopen($incident, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $incident->displayName().' has been reopened.');
    }

    /**
     * Put the incident into the company's HSE review route.
     *
     * The whole of Phase 4's exit criterion hangs off this endpoint: an administrator configures a
     * route for `hse_incident` with a `create_record` action, somebody submits an incident here, and
     * the investigation and its corrective action open themselves. Nothing in this class names
     * either of them.
     */
    public function submit(Request $request, Incident $incident): RedirectResponse
    {
        return $this->submitForApproval($request, $incident);
    }

    public function transition(Request $request, Incident $incident): RedirectResponse
    {
        return $this->moveStatus($request, $incident);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The nested route says which incident; the bound person says which incident it really belongs
     * to. Comparing them stops `/incidents/1/people/94` amending a row that hangs off incident 12 —
     * the tenant scope has already made cross-company ids unresolvable, and this closes the
     * within-tenant half of the same hole (§84, §94).
     */
    private function assertBelongsTo(Incident $incident, IncidentPerson $person): void
    {
        abort_unless((int) $person->incident_id === (int) $incident->getKey(), 404);
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'categories' => $this->lists->for('hse_incident.categories', Incident::DEFAULT_CATEGORIES),
            'severities' => $this->lists->for('hse_incident.severities', Incident::DEFAULT_SEVERITIES),
            'nextNumber' => $this->numbers->preview('hse_incident'),
        ];
    }

    /** @return array<string,mixed> */
    private function personOptions(Request $request): array
    {
        return [
            'involvements' => $this->lists->for('hse_incident.involvements', IncidentPerson::DEFAULT_INVOLVEMENTS),
            'treatments' => $this->lists->for('hse_incident.treatments', IncidentPerson::DEFAULT_TREATMENTS),
            'people' => $this->userOptions($request->user()),
        ];
    }
}
