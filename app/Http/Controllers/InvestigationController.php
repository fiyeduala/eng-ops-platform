<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\InvestigationRequest;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Services\InvestigationService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Why it happened.
 *
 * AN INVESTIGATION CANNOT BE CREATED ON ITS OWN. There is no `create` screen and no top-level store
 * action: the only way to open one is `store()` against a specific incident, and the incident is
 * the ROUTE parameter, never a posted field. That is not a UI decision. `incident_id` is the column
 * that decides which project the record lands in, who can reach it and what it is about, so
 * accepting it from a payload would let somebody staple an investigation to an incident they cannot
 * open and read its number, title and project back off the show page (§84, §94).
 *
 * ONE INCIDENT, ONE INVESTIGATION. `Incident::investigation()` is a HasOne, so a second POST is
 * refused here rather than quietly producing a duplicate — which matters more than it looks,
 * because the workflow engine can fire the same configured action twice if a stage is re-entered.
 * The exit criterion's idempotence rests on this check.
 *
 * COMPLETION REFUSES WITHOUT A ROOT CAUSE, in the policy and again in the service. An investigation
 * signed off with only an immediate cause is the reason the same incident happens again in eighteen
 * months, and it is the one rule this module exists to enforce.
 */
class InvestigationController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly InvestigationService $investigations,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'investigation';
    }

    protected function modelClass(): string
    {
        return Investigation::class;
    }

    protected function routePrefix(): string
    {
        return 'investigations';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Investigation::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'incident:id,number,title',
            'lead:id,name',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($method = (string) $request->query('method', '')) {
            $query->where('method', $method);
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'mine' => $query->outstanding()->where('lead_id', $user->id),
            'overdue' => $query->outstanding()->whereNotNull('due_on')
                ->whereDate('due_on', '<', now()->startOfDay()),
            'completed' => $query->whereNotNull('completed_on'),
            'all' => $query,
            default => $query->outstanding(),
        };

        /** @var LengthAwarePaginator $investigations */
        $investigations = $query
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('investigations.index', [
            'investigations' => $investigations,
            'statuses' => $this->statuses->optionsFor('investigation'),
            'projects' => $this->projectOptions($user),
            'methods' => $this->lists->for('investigation.methods', Investigation::DEFAULT_METHODS),
            'views' => [
                'open' => 'Outstanding',
                'mine' => 'Led by me',
                'overdue' => 'Late',
                'completed' => 'Completed',
                'all' => 'Everything you can see',
            ],
            'filters' => [...$this->commonFilters($request), 'method' => $request->query('method')],
        ]);
    }

    public function show(Request $request, Investigation $investigation): View
    {
        $this->authorize('view', $investigation);

        $user = $request->user();

        $investigation->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'incident:id,number,title,occurred_at,severity',
            'lead:id,name', 'creator:id,name',
            'correctiveActions.status', 'correctiveActions.assignee:id,name',
        ]);

        return view('investigations.show', [
            'investigation' => $investigation,
            'timeline' => $this->activity->timelineFor($investigation)->limit(25)->get(),
            'transitions' => $this->statuses->available('investigation', $investigation->status_id, $user),
            'workflowInstance' => $investigation->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $investigation),
            'canDelete' => $user->can('delete', $investigation),
            'canComplete' => $user->can('complete', $investigation),
            'canReopen' => $user->can('reopen', $investigation),

            // The recommendations an investigation produces have to become somebody's job before
            // they are worth anything, so the action is raised from this page rather than from a
            // separate screen the investigator would have to remember to visit.
            'canRaiseAction' => $user->can('create', CorrectiveAction::class),
            'actionTypes' => $this->lists->for('corrective_action.types', CorrectiveAction::DEFAULT_TYPES),
            'actionPriorities' => $this->lists->for(
                'corrective_action.priorities',
                CorrectiveAction::DEFAULT_PRIORITIES,
            ),
            'people' => $this->userOptions($user),
        ]);
    }

    /**
     * Open the investigation into this incident.
     *
     * The request carries no `incident_id` and this method never looks for one — the incident is the
     * route parameter, and the service copies its project, discipline, unit and classification
     * across. The form can still nominate a DIFFERENT placement, so the reachability check runs here
     * exactly as it does on an ordinary store: being allowed to open an investigation into an
     * incident is not permission to file it into a project you cannot open (§84, §94).
     */
    public function store(InvestigationRequest $request, Incident $incident): RedirectResponse
    {
        $this->authorize('createFor', [Investigation::class, $incident]);

        if ($incident->investigation()->exists()) {
            return back()->withErrors([
                'investigation' => $incident->number.' already has an investigation.',
            ]);
        }

        $data = $request->investigationAttributes();

        $this->assertPlacementIsReachable($data);

        $investigation = $this->investigations->openFor(
            $incident,
            $data,
            isset($data['lead_id']) ? (int) $data['lead_id'] : null,
            $request->user(),
        );

        // AN INVESTIGATION IS NEVER CLASSIFIED MORE OPENLY THAN THE INCIDENT IT IS ABOUT. `openFor()`
        // copies the incident's classification across, and the second pass below would otherwise let
        // the posted value overwrite it — turning a confidential incident's findings, which quote its
        // title and number, into something the whole organization can read (§68, §84). Tightening is
        // allowed; loosening is clamped back to the source.
        if (isset($data['confidentiality']) && $incident->confidentiality !== null) {
            $posted = Confidentiality::from((string) $data['confidentiality']);

            if ($posted->rank() < $incident->confidentiality->rank()) {
                $data['confidentiality'] = $incident->confidentiality->value;
            }
        }

        // The narrative fields are not part of `openFor()`'s vocabulary — it exists to be callable
        // by a workflow action, which has no findings to give it — so anything typed on the form
        // goes on in a second pass through the ordinary update path, where the protected-attribute
        // list still applies.
        $this->investigations->update($investigation, $data, $request->user());

        return redirect($this->showRoute($investigation))
            ->with('status', $investigation->displayName().' has been opened.');
    }

    public function edit(Request $request, Investigation $investigation): View
    {
        $this->authorize('update', $investigation);

        $investigation->loadMissing('incident:id,number,title');

        return view('investigations.edit', [
            'investigation' => $investigation,
            ...$this->formOptions($request),
        ]);
    }

    public function update(InvestigationRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $data = $request->investigationAttributes();

        $this->assertPlacementIsReachable($data);

        $this->investigations->update($investigation, $data, $request->user());

        return redirect($this->showRoute($investigation))
            ->with('status', $investigation->displayName().' has been updated.');
    }

    public function destroy(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('delete', $investigation);

        $name = $investigation->displayName();
        $incident = $investigation->incident()->first();

        $this->recordDeletion($investigation, $request->user(), 'investigation');

        $investigation->delete();

        return redirect($incident !== null ? route('incidents.show', $incident) : $this->indexRoute())
            ->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ lifecycle

    public function complete(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('complete', $investigation);

        $validated = $request->validate([
            'findings' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $this->investigations->complete($investigation, $request->user(), $validated['findings'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['complete' => $e->getMessage()]);
        }

        return back()->with('status', $investigation->displayName().' has been completed.');
    }

    public function reopen(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('reopen', $investigation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->investigations->reopen($investigation, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $investigation->displayName().' has been reopened.');
    }

    public function transition(Request $request, Investigation $investigation): RedirectResponse
    {
        return $this->moveStatus($request, $investigation);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'methods' => $this->lists->for('investigation.methods', Investigation::DEFAULT_METHODS),
        ];
    }
}
