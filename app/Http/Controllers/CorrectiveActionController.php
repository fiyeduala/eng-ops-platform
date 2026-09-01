<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\CorrectiveActionRequest;
use App\Models\CorrectiveAction;
use App\Models\HseInspection;
use App\Models\HseObservation;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\Ncr;
use App\Services\CorrectiveActionService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * What we are going to do about it — the register that decides whether any of the others matter.
 *
 * THERE IS ONE `store` PER SOURCE TYPE AND NO `source_type` FIELD ANYWHERE. Five nested endpoints —
 * incident, investigation, observation, inspection, NCR — each bind a real model through route
 * binding, which means the tenant scope has already run and the actor has already been authorized
 * against that record. The alternative, a posted `source_type` / `source_id` pair, is the classic
 * IDOR read: post another record's id and its number and title come back rendered on the show page
 * of an action you own (§84, §94).
 *
 * AN ACTION CAN ALSO STAND ALONE. `source` is nullable on purpose — an action agreed at a management
 * review belongs in the same register as one raised off an incident, and a register that could only
 * hold the second would send the first to a spreadsheet.
 *
 * NOBODY VERIFIES THEIR OWN. The policy refuses the assignee and the completer at any scope, and
 * CorrectiveActionService refuses them again so the rule holds for a workflow action and an import
 * as well as this controller. It is the only separation-of-duties rule in the HSE registers, and it
 * is here because an action nobody independent checked is an action nobody did.
 *
 * `cancel` RUNS ON THE `close` ABILITY, and the status it reaches is `cancelled`, not `closed`.
 * There is no `closed`: `verified` is where a completed action ends, so the only remaining meaning
 * for a close is "abandoned", and counting abandonments as closures is how a register stops being
 * believed. See CorrectiveActionService::cancel().
 */
class CorrectiveActionController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly CorrectiveActionService $actions,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'corrective_action';
    }

    protected function modelClass(): string
    {
        return CorrectiveAction::class;
    }

    protected function routePrefix(): string
    {
        return 'corrective-actions';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CorrectiveAction::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'assignee:id,name',
            'source',
        ]);

        $this->applyCommonFilters($query, $request);

        foreach (['action_type', 'priority'] as $field) {
            if ($value = (string) $request->query($field, '')) {
                $query->where($field, $value);
            }
        }

        // `mine` is the default view and that is the whole design of this screen. Every other
        // register opens on "everything open"; this one opens on "what you personally owe",
        // because an action register that does not answer that question first gets ignored.
        $view = (string) $request->query('view', 'mine');

        match ($view) {
            'open' => $query->outstanding(),
            'verification' => $query->awaitingVerification(),
            'overdue' => $query->outstanding()->whereNotNull('due_on')
                ->whereDate('due_on', '<', now()->startOfDay()),
            'all' => $query,
            default => $query->outstanding()->assignedTo($user->id),
        };

        /** @var LengthAwarePaginator $actions */
        $actions = $query
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('corrective-actions.index', [
            'actions' => $actions,
            'statuses' => $this->statuses->optionsFor('corrective_action'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('corrective_action.types', CorrectiveAction::DEFAULT_TYPES),
            'priorities' => $this->lists->for('corrective_action.priorities', CorrectiveAction::DEFAULT_PRIORITIES),
            'views' => [
                'mine' => 'Assigned to me',
                'open' => 'Outstanding',
                'overdue' => 'Late',
                'verification' => 'Awaiting verification',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request, 'mine'),
                'action_type' => $request->query('action_type'),
                'priority' => $request->query('priority'),
            ],
            'canCreate' => $user->can('create', CorrectiveAction::class),
        ]);
    }

    public function show(Request $request, CorrectiveAction $correctiveAction): View
    {
        $this->authorize('view', $correctiveAction);

        $user = $request->user();

        $correctiveAction->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'assignee:id,name', 'completer:id,name', 'verifier:id,name', 'creator:id,name',
        ]);

        // THE SOURCE IS RESOLVED THROUGH THE POLICY, NOT JUST THE RELATION. An action is routinely
        // assigned to somebody who cannot read the incident it came from — a supervisor told to fit
        // a guard rail has no business with the injury detail — so the link is offered only when
        // this actor could open the source, and the action's own title is what carries the work
        // instruction (§68).
        $source = $correctiveAction->source()->first();

        $canSeeSource = $source instanceof Model && $user->can('view', $source);

        return view('corrective-actions.show', [
            'action' => $correctiveAction,
            'source' => $canSeeSource ? $source : null,
            'timeline' => $this->activity->timelineFor($correctiveAction)->limit(25)->get(),
            'transitions' => $this->statuses->available('corrective_action', $correctiveAction->status_id, $user),
            'workflowInstance' => $correctiveAction->currentWorkflowInstance(),
            'effectiveness' => $this->lists->for(
                'corrective_action.effectiveness',
                CorrectiveAction::DEFAULT_EFFECTIVENESS,
            ),
            'people' => $this->userOptions($user),
            'canEdit' => $user->can('update', $correctiveAction),
            'canDelete' => $user->can('delete', $correctiveAction),
            'canAssign' => $user->can('assign', $correctiveAction),
            'canComplete' => $user->can('complete', $correctiveAction),
            'canVerify' => $user->can('verify', $correctiveAction),
            'canCancel' => $user->can('close', $correctiveAction),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', CorrectiveAction::class);

        return view('corrective-actions.create', [
            'action' => new CorrectiveAction([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'action_type' => 'corrective',
            ]),
            ...$this->formOptions($request),
        ]);
    }

    /** A standalone action — agreed at a review, raised off an audit, noticed by somebody. */
    public function store(CorrectiveActionRequest $request): RedirectResponse
    {
        $this->authorize('create', CorrectiveAction::class);

        $data = $request->correctiveActionAttributes();

        $this->assertPlacementIsReachable($data);

        $action = $this->actions->create($data, $request->user());

        return redirect($this->showRoute($action))
            ->with('status', $action->displayName().' has been raised.');
    }

    public function edit(Request $request, CorrectiveAction $correctiveAction): View
    {
        $this->authorize('update', $correctiveAction);

        return view('corrective-actions.edit', [
            'action' => $correctiveAction,
            ...$this->formOptions($request),
        ]);
    }

    public function update(CorrectiveActionRequest $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        $this->authorize('update', $correctiveAction);

        $data = $request->correctiveActionAttributes();

        $this->assertPlacementIsReachable($data);

        $this->actions->update($correctiveAction, $data, $request->user());

        return redirect($this->showRoute($correctiveAction))
            ->with('status', $correctiveAction->displayName().' has been updated.');
    }

    public function destroy(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        $this->authorize('delete', $correctiveAction);

        $name = $correctiveAction->displayName();

        $this->recordDeletion($correctiveAction, $request->user(), 'corrective action');

        $correctiveAction->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ raising from a source

    public function storeForIncident(CorrectiveActionRequest $request, Incident $incident): RedirectResponse
    {
        return $this->raiseFrom($request, $incident);
    }

    public function storeForInvestigation(
        CorrectiveActionRequest $request,
        Investigation $investigation,
    ): RedirectResponse {
        return $this->raiseFrom($request, $investigation);
    }

    public function storeForObservation(
        CorrectiveActionRequest $request,
        HseObservation $hseObservation,
    ): RedirectResponse {
        return $this->raiseFrom($request, $hseObservation);
    }

    public function storeForInspection(
        CorrectiveActionRequest $request,
        HseInspection $hseInspection,
    ): RedirectResponse {
        return $this->raiseFrom($request, $hseInspection);
    }

    public function storeForNcr(CorrectiveActionRequest $request, Ncr $ncr): RedirectResponse
    {
        return $this->raiseFrom($request, $ncr);
    }

    // ------------------------------------------------------------------ lifecycle

    public function assign(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        $this->authorize('assign', $correctiveAction);

        $validated = $request->validate([
            // Nullable and not required: unassigning is a real act. An action whose owner left the
            // company must be returnable to the pool rather than left pointing at a dead account.
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $assignee = $validated['assigned_to'] ?? null;

        // Resolved against the scoped list rather than trusted. `existsInTenant` would only prove
        // the id is in this company; this proves the actor could have picked the person off the
        // form they were given (§61, §84).
        if ($assignee !== null && ! array_key_exists((int) $assignee, $this->userOptions($request->user()))) {
            return back()->withErrors(['assigned_to' => 'That is not somebody you can assign work to.']);
        }

        $this->actions->assign($correctiveAction, $assignee !== null ? (int) $assignee : null, $request->user());

        return back()->with('status', $correctiveAction->displayName().' has been reassigned.');
    }

    public function complete(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        $this->authorize('complete', $correctiveAction);

        $validated = $request->validate([
            // Required, and the service requires it again. "Done" is not evidence; the note is what
            // the verifier reads before deciding whether it actually was.
            'completion_notes' => ['required', 'string', 'max:10000'],
        ]);

        try {
            $this->actions->complete($correctiveAction, $request->user(), $validated['completion_notes']);
        } catch (\DomainException $e) {
            return back()->withErrors(['completion_notes' => $e->getMessage()]);
        }

        return back()->with('status', $correctiveAction->displayName().' has been marked complete.');
    }

    public function verify(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        $this->authorize('verify', $correctiveAction);

        $validated = $request->validate([
            'effectiveness' => [
                'required',
                Rule::in($this->lists->keys(
                    'corrective_action.effectiveness',
                    CorrectiveAction::DEFAULT_EFFECTIVENESS,
                )),
            ],
            'verification_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        try {
            $this->actions->verify(
                $correctiveAction,
                $request->user(),
                $validated['effectiveness'],
                $validated['verification_notes'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['effectiveness' => $e->getMessage()]);
        }

        return back()->with('status', $correctiveAction->displayName().' has been verified.');
    }

    public function cancel(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        $this->authorize('close', $correctiveAction);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->actions->cancel($correctiveAction, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $correctiveAction->displayName().' has been withdrawn.');
    }

    public function transition(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        return $this->moveStatus($request, $correctiveAction);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The shared body of the five nested store actions.
     *
     * TWO PERMISSIONS, ASKED SEPARATELY. `view` on the source proves the actor can reach the record
     * they are raising the action off — without it, a nested route would be a way to discover that
     * incident 412 exists. `create` on CorrectiveAction proves they are allowed to raise actions at
     * all. Neither implies the other, and collapsing them would either lock out supervisors who
     * legitimately raise actions or let anybody who can raise one attach it to anything.
     */
    private function raiseFrom(CorrectiveActionRequest $request, Model $source): RedirectResponse
    {
        $this->authorize('view', $source);
        $this->authorize('create', CorrectiveAction::class);

        $data = $request->correctiveActionAttributes();

        $this->assertPlacementIsReachable($data);

        $action = $this->actions->openFrom(
            $source,
            $data,
            isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            $request->user(),
        );

        return redirect($this->showRoute($action))
            ->with('status', $action->displayName().' has been raised.');
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'types' => $this->lists->for('corrective_action.types', CorrectiveAction::DEFAULT_TYPES),
            'priorities' => $this->lists->for('corrective_action.priorities', CorrectiveAction::DEFAULT_PRIORITIES),
            'nextNumber' => $this->numbers->preview('corrective_action'),
        ];
    }
}
