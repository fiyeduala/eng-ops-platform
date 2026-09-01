<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\QualityInspectionItemsRequest;
use App\Http\Requests\QualityInspectionRequest;
use App\Models\Ncr;
use App\Models\QualityInspection;
use App\Models\QualityInspectionItem;
use App\Services\QualityInspectionService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Come and look before we pour." — the request for inspection, and the verdict.
 *
 * REQUESTING IS BROAD, INSPECTING IS NARROW, AND THEY ARE DIFFERENT ENDPOINTS. Any site engineer
 * raises an RFI-for-inspection; only somebody holding `quality_inspection.approve` records what was
 * found, and never on their own request. Both refusals are in the policy and again in the service,
 * because a hold point that releases itself is not a hold point (§6, §84).
 *
 * ONE VERDICT, AND THEN THE RECORD IS TESTIMONY. `inspected_at` is the line: after it the policy
 * refuses `update` and `delete` outright, and the service refuses a second verdict. What was found
 * at a hold point is read years later by a certifying authority, and a record that could be quietly
 * revised afterwards would be worth nothing to them (§59).
 *
 * THE CHECKLIST IS SET BEFORE THE WALK, THROUGH ITS OWN ENDPOINT. Acceptance criteria written after
 * the results are in are not criteria. The service refuses `setItems()` on an inspected record.
 *
 * A REJECTION IS NOT AN NCR. Rejecting says what was found; raising a non-conformance — with a
 * disposition, a cost and a verification — is a separate decision on a separate record, opened from
 * this page through the same `create_record` verb an incident uses to open its investigation.
 */
class QualityInspectionController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly QualityInspectionService $inspections,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'quality_inspection';
    }

    protected function modelClass(): string
    {
        return QualityInspection::class;
    }

    protected function routePrefix(): string
    {
        return 'quality-inspections';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', QualityInspection::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'inspector:id,name', 'creator:id,name',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        if ($type = (string) $request->query('inspection_type', '')) {
            $query->where('inspection_type', $type);
        }

        $view = (string) $request->query('view', 'awaiting');

        match ($view) {
            'rejected' => $query->rejected(),
            'inspected' => $query->whereNotNull('inspected_at'),
            'mine' => $query->where(fn ($q) => $q->where('created_by', $user->id)
                ->orWhere('inspected_by', $user->id)),
            'all' => $query,
            default => $query->awaiting(),
        };

        /** @var LengthAwarePaginator $inspections */
        $inspections = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('quality-inspections.index', [
            'inspections' => $inspections,
            'statuses' => $this->statuses->optionsFor('quality_inspection'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('quality_inspection.types', QualityInspection::DEFAULT_TYPES),

            // The stored value is a slug; the register has to print a label, and the labels are
            // configurable per organization. Passing the list is cheaper than teaching the view how
            // to derive "Passed with comment" from "passed_with_comment" and getting it wrong.
            'results' => $this->lists->for('quality_inspection.results', QualityInspection::DEFAULT_RESULTS),
            'views' => [
                'awaiting' => 'Awaiting inspection',
                'rejected' => 'Rejected',
                'inspected' => 'Inspected',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request, 'awaiting'),
                'inspection_type' => $request->query('inspection_type'),
            ],
            'canCreate' => $user->can('create', QualityInspection::class),
        ]);
    }

    public function show(Request $request, QualityInspection $qualityInspection): View
    {
        $this->authorize('view', $qualityInspection);

        $user = $request->user();

        $qualityInspection->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'inspector:id,name', 'creator:id,name',
            'items',
            'ncrs.status',
        ]);

        return view('quality-inspections.show', [
            'inspection' => $qualityInspection,
            'timeline' => $this->activity->timelineFor($qualityInspection)->limit(25)->get(),
            'transitions' => $this->statuses->available('quality_inspection', $qualityInspection->status_id, $user),
            'workflowInstance' => $qualityInspection->currentWorkflowInstance(),
            'results' => $this->lists->for('quality_inspection.results', QualityInspection::DEFAULT_RESULTS),
            'itemResults' => $this->lists->for(
                'quality_inspection.item_results',
                QualityInspectionItem::DEFAULT_RESULTS,
            ),
            'types' => $this->lists->for('quality_inspection.types', QualityInspection::DEFAULT_TYPES),
            'canEdit' => $user->can('update', $qualityInspection),
            'canDelete' => $user->can('delete', $qualityInspection),
            'canInspect' => $user->can('inspect', $qualityInspection),
            'canRaiseNcr' => $user->can('create', Ncr::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', QualityInspection::class);

        return view('quality-inspections.create', [
            'inspection' => new QualityInspection([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'scheduled_for' => now()->addDay()->format('Y-m-d\TH:i'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(QualityInspectionRequest $request): RedirectResponse
    {
        $this->authorize('create', QualityInspection::class);

        $data = $request->inspectionAttributes();

        $this->assertPlacementIsReachable($data);

        $inspection = $this->inspections->create($data, $request->user());

        return redirect(route('quality-inspections.items.edit', $inspection))
            ->with('status', $inspection->displayName().' has been requested — now set the acceptance criteria.');
    }

    public function edit(Request $request, QualityInspection $qualityInspection): View
    {
        $this->authorize('update', $qualityInspection);

        return view('quality-inspections.edit', [
            'inspection' => $qualityInspection,
            ...$this->formOptions($request),
        ]);
    }

    public function update(QualityInspectionRequest $request, QualityInspection $qualityInspection): RedirectResponse
    {
        $this->authorize('update', $qualityInspection);

        $data = $request->inspectionAttributes();

        $this->assertPlacementIsReachable($data);

        $this->inspections->update($qualityInspection, $data, $request->user());

        return redirect($this->showRoute($qualityInspection))
            ->with('status', $qualityInspection->displayName().' has been updated.');
    }

    public function destroy(Request $request, QualityInspection $qualityInspection): RedirectResponse
    {
        $this->authorize('delete', $qualityInspection);

        $name = $qualityInspection->displayName();

        $this->recordDeletion($qualityInspection, $request->user(), 'inspection request');

        $qualityInspection->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ checklist

    public function editItems(Request $request, QualityInspection $qualityInspection): View
    {
        $this->authorize('update', $qualityInspection);

        $qualityInspection->loadMissing('items');

        return view('quality-inspections.items', [
            'inspection' => $qualityInspection,
        ]);
    }

    public function updateItems(
        QualityInspectionItemsRequest $request,
        QualityInspection $qualityInspection,
    ): RedirectResponse {
        $this->authorize('update', $qualityInspection);

        try {
            $this->inspections->setItems($qualityInspection, $request->itemRows(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect($this->showRoute($qualityInspection))
            ->with('status', 'The acceptance criteria for '.$qualityInspection->displayName().' have been set.');
    }

    // ------------------------------------------------------------------ verdict

    /**
     * Attend, and record what was found.
     *
     * The RESULT is validated against the configured list because it is the one value that decides
     * where the record lands; everything else about the line items is shape-checked only, and the
     * service matches the posted ids against this inspection's own rows. Membership is answered in
     * one place, not two (§84).
     *
     * The comment requirement for anything short of a clean pass is the service's, not this
     * method's, for the same reason: a workflow action or an import that skipped this controller
     * must meet it too.
     */
    public function inspect(Request $request, QualityInspection $qualityInspection): RedirectResponse
    {
        $this->authorize('inspect', $qualityInspection);

        $validated = $request->validate([
            'result' => [
                'required',
                Rule::in($this->lists->keys('quality_inspection.results', QualityInspection::DEFAULT_RESULTS)),
            ],
            'comments' => ['nullable', 'string', 'max:20000'],
            'results' => ['nullable', 'array', 'max:300'],
            'results.*.result' => ['nullable', 'string', 'max:60'],
            'results.*.measured_value' => ['nullable', 'string', 'max:120'],
            'results.*.comment' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->inspections->inspect(
                $qualityInspection,
                (string) $validated['result'],
                $request->user(),
                $validated['results'] ?? [],
                $validated['comments'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['result' => $e->getMessage()]);
        }

        return back()->with('status', $qualityInspection->displayName().' has been inspected.');
    }

    public function transition(Request $request, QualityInspection $qualityInspection): RedirectResponse
    {
        return $this->moveStatus($request, $qualityInspection);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'types' => $this->lists->for('quality_inspection.types', QualityInspection::DEFAULT_TYPES),
            'nextNumber' => $this->numbers->preview('quality_inspection'),
        ];
    }
}
