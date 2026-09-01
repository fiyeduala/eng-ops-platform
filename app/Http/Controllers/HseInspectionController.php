<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\HseInspectionItemsRequest;
use App\Http\Requests\HseInspectionRequest;
use App\Models\CorrectiveAction;
use App\Models\HseInspection;
use App\Models\HseInspectionItem;
use App\Services\HseInspectionService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The site walk — planned, then carried out, then closed.
 *
 * THREE ENDPOINTS FOR THREE DIFFERENT ACTS, AND THEY ARE NOT INTERCHANGEABLE. `store`/`update` plan
 * an inspection; `updateItems` writes the checklist it will be carried out against; `perform`
 * records what was actually found. Merging any two of them would let the results be edited by
 * whoever can edit the schedule, and an inspection whose findings can be quietly revised afterwards
 * is not evidence of anything (§59).
 *
 * THE CHECKLIST IS FROZEN ONCE THE WALK HAS HAPPENED. The service refuses `setItems()` on a
 * performed inspection, because changing the questions after the answers are in is how a 62% score
 * becomes a 95% one.
 *
 * RESULTS ARE MATCHED AGAINST THIS INSPECTION'S OWN ROWS. `perform()` takes an array keyed by item
 * id and writes only to items that belong to this record, so a posted id from another inspection —
 * another project's, another company's — writes nothing at all rather than being rejected with an
 * error that confirms it exists (§84, §94).
 */
class HseInspectionController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly HseInspectionService $inspections,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'hse_inspection';
    }

    protected function modelClass(): string
    {
        return HseInspection::class;
    }

    protected function routePrefix(): string
    {
        return 'hse-inspections';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HseInspection::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'inspector:id,name',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        if ($type = (string) $request->query('inspection_type', '')) {
            $query->where('inspection_type', $type);
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'due' => $query->due(),
            'performed' => $query->whereNotNull('performed_at')->whereNull('closed_at'),
            'mine' => $query->where(fn ($q) => $q->where('performed_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->open(),
        };

        /** @var LengthAwarePaginator $inspections */
        $inspections = $query
            ->orderByRaw('CASE WHEN scheduled_for IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_for')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('hse-inspections.index', [
            'inspections' => $inspections,
            'statuses' => $this->statuses->optionsFor('hse_inspection'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('hse_inspection.types', HseInspection::DEFAULT_TYPES),
            'views' => [
                'open' => 'Open',
                'due' => 'Due now',
                'performed' => 'Carried out, not closed',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'inspection_type' => $request->query('inspection_type'),
            ],
            'canCreate' => $user->can('create', HseInspection::class),
        ]);
    }

    public function show(Request $request, HseInspection $hseInspection): View
    {
        $this->authorize('view', $hseInspection);

        $user = $request->user();

        $hseInspection->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'inspector:id,name', 'closer:id,name', 'creator:id,name',
            'items',
            'correctiveActions.status', 'correctiveActions.assignee:id,name',
        ]);

        return view('hse-inspections.show', [
            'inspection' => $hseInspection,
            'timeline' => $this->activity->timelineFor($hseInspection)->limit(25)->get(),
            'transitions' => $this->statuses->available('hse_inspection', $hseInspection->status_id, $user),
            'workflowInstance' => $hseInspection->currentWorkflowInstance(),
            'itemResults' => $this->lists->for('hse_inspection.results', HseInspectionItem::DEFAULT_RESULTS),
            'canEdit' => $user->can('update', $hseInspection),
            'canDelete' => $user->can('delete', $hseInspection),
            'canPerform' => $user->can('perform', $hseInspection),
            'canClose' => $user->can('close', $hseInspection),
            'canRaiseAction' => $user->can('create', CorrectiveAction::class),
            ...$this->actionOptions($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', HseInspection::class);

        return view('hse-inspections.create', [
            'inspection' => new HseInspection([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'scheduled_for' => now()->toDateString(),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(HseInspectionRequest $request): RedirectResponse
    {
        $this->authorize('create', HseInspection::class);

        $data = $request->inspectionAttributes();

        $this->assertPlacementIsReachable($data);

        $inspection = $this->inspections->create($data, $request->user());

        return redirect(route('hse-inspections.items.edit', $inspection))
            ->with('status', $inspection->displayName().' has been scheduled — now set the checklist.');
    }

    public function edit(Request $request, HseInspection $hseInspection): View
    {
        $this->authorize('update', $hseInspection);

        return view('hse-inspections.edit', [
            'inspection' => $hseInspection,
            ...$this->formOptions($request),
        ]);
    }

    public function update(HseInspectionRequest $request, HseInspection $hseInspection): RedirectResponse
    {
        $this->authorize('update', $hseInspection);

        $data = $request->inspectionAttributes();

        $this->assertPlacementIsReachable($data);

        $this->inspections->update($hseInspection, $data, $request->user());

        return redirect($this->showRoute($hseInspection))
            ->with('status', $hseInspection->displayName().' has been updated.');
    }

    public function destroy(Request $request, HseInspection $hseInspection): RedirectResponse
    {
        $this->authorize('delete', $hseInspection);

        $name = $hseInspection->displayName();

        $this->recordDeletion($hseInspection, $request->user(), 'inspection');

        $hseInspection->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ checklist

    public function editItems(Request $request, HseInspection $hseInspection): View
    {
        $this->authorize('update', $hseInspection);

        $hseInspection->loadMissing('items');

        return view('hse-inspections.items', [
            'inspection' => $hseInspection,
        ]);
    }

    public function updateItems(
        HseInspectionItemsRequest $request,
        HseInspection $hseInspection,
    ): RedirectResponse {
        $this->authorize('update', $hseInspection);

        try {
            $this->inspections->setItems($hseInspection, $request->itemRows(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect($this->showRoute($hseInspection))
            ->with('status', 'The checklist for '.$hseInspection->displayName().' has been set.');
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * Record what was found.
     *
     * The results arrive keyed by item id and the service matches them against this inspection's
     * own rows. Validation here is deliberately shallow — the shape, not the membership — because
     * the membership question is one only the service can answer, and answering it twice in two
     * places is how the two answers drift apart (§84).
     */
    public function perform(Request $request, HseInspection $hseInspection): RedirectResponse
    {
        $this->authorize('perform', $hseInspection);

        $validated = $request->validate([
            'summary' => ['nullable', 'string', 'max:20000'],
            'results' => ['nullable', 'array', 'max:500'],
            'results.*.result' => ['nullable', 'string', 'max:60'],
            'results.*.severity' => ['nullable', 'string', 'max:40'],
            'results.*.comment' => ['nullable', 'string', 'max:5000'],
            'results.*.action_required' => ['nullable', 'boolean'],
            'results.*.score_achieved' => ['nullable', 'numeric', 'between:0,9999'],
        ]);

        try {
            $this->inspections->perform(
                $hseInspection,
                $validated['results'] ?? [],
                $request->user(),
                $validated['summary'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['perform' => $e->getMessage()]);
        }

        return back()->with('status', $hseInspection->displayName().' has been recorded.');
    }

    public function close(Request $request, HseInspection $hseInspection): RedirectResponse
    {
        $this->authorize('close', $hseInspection);

        try {
            $this->inspections->close($hseInspection, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $hseInspection->displayName().' has been closed.');
    }

    public function transition(Request $request, HseInspection $hseInspection): RedirectResponse
    {
        return $this->moveStatus($request, $hseInspection);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'types' => $this->lists->for('hse_inspection.types', HseInspection::DEFAULT_TYPES),
            'nextNumber' => $this->numbers->preview('hse_inspection'),
        ];
    }

    /** @return array<string,mixed> */
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
