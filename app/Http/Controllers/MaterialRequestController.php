<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\MaterialRequestDecisionRequest;
use App\Http\Requests\MaterialRequestRequest;
use App\Models\Location;
use App\Models\MaterialRequest;
use App\Models\Status;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\MaterialRequestService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Asking the store for something we already own.
 *
 * NOT A PURCHASE REQUEST, and the two are kept apart deliberately. A purchase request asks the
 * company to spend money; this asks the storekeeper to hand something over. They are raised by
 * different people, approved against different questions — "can we afford it" against "can we
 * spare it" — and settled by different acts. Merging them would mean every issue of four bolts
 * went through a procurement approval, which is how a store stops being used and material starts
 * walking out unrecorded.
 *
 * THE APPROVER MAY CUT, NEVER RAISE. MaterialRequestService clamps each approved quantity to what
 * was asked for, so nobody can approve their way into issuing more than the site requested. A line
 * nobody touched means "as asked for" rather than zero — the commonest decision is agreement, and
 * a form that made agreement the laborious answer would be filled in carelessly.
 *
 * ISSUING IS THE LEDGER'S BUSINESS. This controller never writes a stock row; it hands the decision
 * to the service, which posts through StockService so that a material request and a goods receipt
 * arrive at the same ledger by the same door. `part_issued` and `issued` are computed from what the
 * ledger actually shows, never clicked, so a request cannot read as satisfied while the shelf says
 * otherwise (§90).
 */
class MaterialRequestController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly MaterialRequestService $requests,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'material_request';
    }

    protected function modelClass(): string
    {
        return MaterialRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'material-requests';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', MaterialRequest::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'orgUnit:id,name',
            'warehouse:id,code,name',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        if ($priority = (string) $request->query('priority', '')) {
            $query->priority($priority);
        }

        if ($warehouseId = (int) $request->query('warehouse', 0)) {
            $query->where('warehouse_id', $warehouseId);
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            'mine' => $query->where('created_by', $user->getKey()),
            'awaiting' => $query->awaitingDecision(),
            // What the storekeeper works from: approved, and not yet fully handed over.
            'to_issue' => $query->whereHas('status', fn ($q) => $q->whereIn('key', ['approved', 'part_issued'])),
            default => $query,
        };

        /** @var LengthAwarePaginator $materialRequests */
        $materialRequests = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('material-requests.index', [
            'materialRequests' => $materialRequests,
            'statuses' => $this->statuses->optionsFor('material_request'),
            'projects' => $this->projectOptions($user),
            'warehouses' => $this->warehouseOptions(),
            'priorities' => $this->lists->for('material_request.priorities', MaterialRequest::DEFAULT_PRIORITIES),
            'views' => [
                'open' => 'Open',
                'awaiting' => 'Waiting on a decision',
                'to_issue' => 'Ready to issue',
                'mine' => 'Raised by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'priority' => $request->query('priority'),
                'warehouse' => $request->query('warehouse'),
            ],
            'canCreate' => $user->can('create', MaterialRequest::class),
        ]);
    }

    public function show(Request $request, MaterialRequest $materialRequest): View
    {
        $this->authorize('view', $materialRequest);

        $user = $request->user();

        $materialRequest->loadMissing([
            'status', 'project', 'orgUnit', 'location', 'warehouse', 'creator:id,name',
            'submitter:id,name', 'approver:id,name', 'issuer:id,name',
            'items.stockItem:id,code,name,uom', 'items.movements',
        ]);

        return view('material-requests.show', [
            'materialRequest' => $materialRequest,
            'timeline' => $this->activity->timelineFor($materialRequest)->limit(25)->get(),
            'transitions' => $this->statuses->available('material_request', $materialRequest->status_id, $user),
            'workflowInstance' => $materialRequest->currentWorkflowInstance(),
            // What is actually on the shelf right now, for the person deciding how much to release.
            // Shown only to somebody who may issue: a requester does not need the store's position
            // to ask for four bolts, and §68 says not to volunteer it.
            'availability' => $user->can('issue', $materialRequest)
                ? $this->availability($materialRequest)
                : [],
            'canEdit' => $user->can('update', $materialRequest),
            'canDelete' => $user->can('delete', $materialRequest),
            'canSubmit' => $user->can('submit', $materialRequest),
            'canApprove' => $user->can('approve', $materialRequest),
            'canReject' => $user->can('reject', $materialRequest),
            'canIssue' => $materialRequest->isIssuable() && $user->can('issue', $materialRequest),
        ]);
    }

    // ------------------------------------------------------------------ writing

    public function create(Request $request): View
    {
        $this->authorize('create', MaterialRequest::class);

        return view('material-requests.create', [
            'materialRequest' => new MaterialRequest([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'warehouse_id' => (int) $request->query('warehouse', 0) ?: null,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(MaterialRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', MaterialRequest::class);

        $data = $request->materialRequestAttributes();

        $this->assertPlacementIsReachable($data);

        $materialRequest = $this->requests->create($data, $request->user());

        return redirect($this->showRoute($materialRequest))
            ->with('status', $materialRequest->displayName().' has been raised.');
    }

    public function edit(Request $request, MaterialRequest $materialRequest): View
    {
        $this->authorize('update', $materialRequest);

        $materialRequest->loadMissing('items');

        return view('material-requests.edit', [
            'materialRequest' => $materialRequest,
            ...$this->formOptions($request),
        ]);
    }

    public function update(MaterialRequestRequest $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('update', $materialRequest);

        $data = $request->materialRequestAttributes();

        $this->assertPlacementIsReachable($data);

        $this->requests->update($materialRequest, $data, $request->user());

        return redirect($this->showRoute($materialRequest))
            ->with('status', $materialRequest->displayName().' has been updated.');
    }

    public function destroy(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('delete', $materialRequest);

        $name = $materialRequest->displayName();

        $this->recordDeletion($materialRequest, $request->user(), 'material request');

        $materialRequest->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ decisions

    public function submit(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('submit', $materialRequest);

        try {
            $this->requests->submit($materialRequest, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $materialRequest->displayName().' has been sent for approval.');
    }

    /**
     * Approve, possibly for less than was asked for.
     *
     * The quantities arrive keyed by line id and the service clamps each one, so a posted id that
     * belongs to another request — or another tenant — matches no line and changes nothing. The
     * form request has already dropped the blanks, which is what makes an untouched line mean
     * "as asked for" rather than "none" (§84).
     */
    public function approve(MaterialRequestDecisionRequest $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('approve', $materialRequest);

        try {
            $this->requests->approve(
                $materialRequest,
                $request->user(),
                $request->quantities(),
                $request->note(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['notes' => $e->getMessage()]);
        }

        return back()->with('status', $materialRequest->displayName().' has been approved.');
    }

    public function reject(MaterialRequestDecisionRequest $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('reject', $materialRequest);

        try {
            $this->requests->reject($materialRequest, $request->user(), (string) $request->note());
        } catch (\DomainException $e) {
            return back()->withErrors(['notes' => $e->getMessage()]);
        }

        return back()->with('status', $materialRequest->displayName().' has been rejected.');
    }

    /**
     * Hand it over. The quantities are what actually left the shelf, which is not always what was
     * approved: the storekeeper found three of the four, or the site only took two today. The
     * service posts each one to the ledger and works the status out from what it posted.
     */
    public function issue(MaterialRequestDecisionRequest $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('issue', $materialRequest);

        try {
            $this->requests->issue(
                $materialRequest,
                $request->user(),
                $request->quantities(),
                $request->note(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['notes' => $e->getMessage()]);
        }

        return back()->with('status', 'The issue has been recorded against '.$materialRequest->number.'.');
    }

    /**
     * Finished with, whether or not everything was handed over.
     *
     * This is the transition that releases the reservation. A request left open forever holds
     * material nobody is coming for, and a store that slowly shows nothing free is a store people
     * stop trusting.
     */
    public function close(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('manage', $materialRequest);

        try {
            $this->requests->close($materialRequest, $request->user(), $request->input('reason', $request->input('comment')));
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $materialRequest->displayName().' has been closed.');
    }

    /**
     * The generic status move, with one door bolted shut.
     *
     * The graph offers `part_issued → closed` and `issued → closed` like any other transition, so
     * posting one through the generic handler would set the status and walk straight past
     * MaterialRequestService::close() — which is the only thing that RELEASES THE RESERVATION.
     * The row would read "closed" while the store went on showing the material as spoken for, and
     * nobody would ever find out why the shelf said four free when there were nine (§84).
     *
     * So every route to `closed` goes through the service, whichever button was pressed.
     */
    public function transition(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $target = Status::query()
            ->forEntity('material_request')
            ->whereKey((int) $request->input('status_id'))
            ->value('key');

        if ($target === 'closed') {
            return $this->close($request, $materialRequest);
        }

        return $this->moveStatus($request, $materialRequest);
    }

    // ------------------------------------------------------------------ internals

    /**
     * What the named store is holding of each requested item, right now.
     *
     * One query for the whole request rather than one per line, and keyed by stock item id so the
     * view can look a line up without asking the database again — this page is opened by a
     * storekeeper with a trolley waiting.
     *
     * @return array<int,float>
     */
    private function availability(MaterialRequest $materialRequest): array
    {
        if ($materialRequest->warehouse_id === null) {
            return [];
        }

        return StockBalance::query()
            ->where('warehouse_id', $materialRequest->warehouse_id)
            ->whereIn('stock_item_id', $materialRequest->items->pluck('stock_item_id')->all())
            ->get(['stock_item_id', 'quantity_on_hand', 'quantity_reserved'])
            ->mapWithKeys(fn ($balance) => [(int) $balance->stock_item_id => (float) $balance->available()])
            ->all();
    }

    /** @return array<int,string> */
    private function warehouseOptions(): array
    {
        return Warehouse::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->code.' — '.$w->name])
            ->all();
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            // Only stores that accept movements: asking a site to raise a request against a closed
            // store produces a request that can never be issued.
            'warehouses' => Warehouse::query()
                ->whereHas('status', fn ($q) => $q->where('key', 'active'))
                ->orderBy('code')->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->code.' — '.$w->name])
                ->all(),
            'items' => StockItem::query()
                ->where('is_stock_item', true)
                ->whereHas('status', fn ($q) => $q->where('key', 'active'))
                ->orderBy('code')->get(['id', 'code', 'name', 'uom'])
                ->mapWithKeys(fn (StockItem $i) => [$i->id => $i->code.' — '.$i->name.' ('.$i->uom.')'])
                ->all(),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'priorities' => $this->lists->for('material_request.priorities', MaterialRequest::DEFAULT_PRIORITIES),
            'nextNumber' => $this->numbers->preview('material_request'),
        ];
    }
}
