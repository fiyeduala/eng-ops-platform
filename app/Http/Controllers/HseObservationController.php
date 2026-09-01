<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\HseObservationRequest;
use App\Models\CorrectiveAction;
use App\Models\HseObservation;
use App\Services\HseObservationService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What somebody noticed before it happened.
 *
 * THIS IS THE SHORTEST FORM IN THE APPLICATION AND THAT IS THE FEATURE. A safety observation card
 * competes with going home. Every field it asks for that could have been optional is a card that
 * does not get filed, and the cards that do not get filed are the ones about the things nobody was
 * willing to put their name to. Title, and everything else optional.
 *
 * ANONYMOUS MEANS ANONYMOUS. When `is_anonymous` is set the service stores no `observed_by` at all —
 * not a hidden one, not a soft-deleted one. A "confidential" reporting scheme whose database still
 * holds the reporter's id is a scheme that will identify somebody the first time a manager asks the
 * right question of the right person, and everyone on site works that out faster than the safety
 * department does. The observer column is not accepted from the form under any circumstances.
 *
 * GOOD PRACTICE IS A LEGITIMATE OBSERVATION TYPE. `Safe behaviour` and `Good practice` are in the
 * default vocabulary deliberately: a register that only ever records failures teaches people that
 * filing a card is an accusation, and the flow of cards stops.
 */
class HseObservationController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly HseObservationService $observations,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'hse_observation';
    }

    protected function modelClass(): string
    {
        return HseObservation::class;
    }

    protected function routePrefix(): string
    {
        return 'hse-observations';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HseObservation::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'observer:id,name',
        ])->withCount('correctiveActions');

        $this->applyCommonFilters($query, $request);

        foreach (['observation_type', 'category', 'risk_level'] as $field) {
            if ($value = (string) $request->query($field, '')) {
                $query->where($field, $value);
            }
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'action' => $query->needingAction(),
            // `observed_by`, not `created_by`. Somebody who filed a card anonymously has no
            // observer id by design, so their own cards do not appear here — which is the correct
            // outcome and is stated on the screen rather than left to be discovered.
            'mine' => $query->where('observed_by', $user->id),
            'closed' => $query->whereNotNull('closed_at'),
            'all' => $query,
            default => $query->open(),
        };

        /** @var LengthAwarePaginator $observations */
        $observations = $query
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('hse-observations.index', [
            'observations' => $observations,
            'statuses' => $this->statuses->optionsFor('hse_observation'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('hse_observation.types', HseObservation::DEFAULT_TYPES),
            'categories' => $this->lists->for('hse_observation.categories', HseObservation::DEFAULT_CATEGORIES),
            'riskLevels' => $this->lists->for('hse_observation.risk_levels', HseObservation::DEFAULT_RISK_LEVELS),
            'views' => [
                'open' => 'Open',
                'action' => 'Action required',
                'mine' => 'Raised by me',
                'closed' => 'Closed',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'observation_type' => $request->query('observation_type'),
                'category' => $request->query('category'),
                'risk_level' => $request->query('risk_level'),
            ],
            'canCreate' => $user->can('create', HseObservation::class),
        ]);
    }

    public function show(Request $request, HseObservation $hseObservation): View
    {
        $this->authorize('view', $hseObservation);

        $user = $request->user();

        $hseObservation->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'observer:id,name', 'closer:id,name', 'creator:id,name',
            'correctiveActions.status', 'correctiveActions.assignee:id,name',
        ]);

        return view('hse-observations.show', [
            'observation' => $hseObservation,
            'timeline' => $this->activity->timelineFor($hseObservation)->limit(25)->get(),
            'transitions' => $this->statuses->available('hse_observation', $hseObservation->status_id, $user),
            'workflowInstance' => $hseObservation->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $hseObservation),
            'canDelete' => $user->can('delete', $hseObservation),
            'canClose' => $user->can('close', $hseObservation),
            'canReopen' => $user->can('reopen', $hseObservation),
            'canRaiseAction' => $user->can('create', CorrectiveAction::class),
            ...$this->actionOptions($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', HseObservation::class);

        return view('hse-observations.create', [
            'observation' => new HseObservation([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'observed_at' => now()->format('Y-m-d\TH:i'),
                'is_anonymous' => false,
                'action_required' => false,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(HseObservationRequest $request): RedirectResponse
    {
        $this->authorize('create', HseObservation::class);

        $data = $request->observationAttributes();

        $this->assertPlacementIsReachable($data);

        $observation = $this->observations->create($data, $request->user());

        return redirect($this->showRoute($observation))
            ->with('status', 'Thank you — '.$observation->displayName().' has been recorded.');
    }

    public function edit(Request $request, HseObservation $hseObservation): View
    {
        $this->authorize('update', $hseObservation);

        return view('hse-observations.edit', [
            'observation' => $hseObservation,
            ...$this->formOptions($request),
        ]);
    }

    public function update(HseObservationRequest $request, HseObservation $hseObservation): RedirectResponse
    {
        $this->authorize('update', $hseObservation);

        $data = $request->observationAttributes();

        $this->assertPlacementIsReachable($data);

        $this->observations->update($hseObservation, $data, $request->user());

        return redirect($this->showRoute($hseObservation))
            ->with('status', $hseObservation->displayName().' has been updated.');
    }

    public function destroy(Request $request, HseObservation $hseObservation): RedirectResponse
    {
        $this->authorize('delete', $hseObservation);

        $name = $hseObservation->displayName();

        $this->recordDeletion($hseObservation, $request->user(), 'observation');

        $hseObservation->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ lifecycle

    public function close(Request $request, HseObservation $hseObservation): RedirectResponse
    {
        $this->authorize('close', $hseObservation);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->observations->close($hseObservation, $request->user(), $validated['notes'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $hseObservation->displayName().' has been closed.');
    }

    public function reopen(Request $request, HseObservation $hseObservation): RedirectResponse
    {
        $this->authorize('reopen', $hseObservation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->observations->reopen($hseObservation, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $hseObservation->displayName().' has been reopened.');
    }

    public function transition(Request $request, HseObservation $hseObservation): RedirectResponse
    {
        return $this->moveStatus($request, $hseObservation);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'types' => $this->lists->for('hse_observation.types', HseObservation::DEFAULT_TYPES),
            'categories' => $this->lists->for('hse_observation.categories', HseObservation::DEFAULT_CATEGORIES),
            'riskLevels' => $this->lists->for('hse_observation.risk_levels', HseObservation::DEFAULT_RISK_LEVELS),
            'nextNumber' => $this->numbers->preview('hse_observation'),
        ];
    }

    /**
     * The fields the "raise an action from this" panel needs.
     *
     * @return array<string,mixed>
     */
    private function actionOptions(Request $request): array
    {
        return [
            'actionTypes' => $this->lists->for('corrective_action.types', CorrectiveAction::DEFAULT_TYPES),
            'actionPriorities' => $this->lists->for(
                'corrective_action.priorities',
                CorrectiveAction::DEFAULT_PRIORITIES,
            ),
            'people' => $this->userOptions($request->user()),
        ];
    }
}
