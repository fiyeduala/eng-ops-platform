<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ProcurementDecisionRequest;
use App\Http\Requests\PurchaseOrderRequest;
use App\Models\Contract;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrderService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The commitment. Everything before it was internal; this is the document a supplier acts on.
 *
 * APPROVED AND ISSUED ARE TWO DIFFERENT DAYS. Approval is internal authority; issue is the moment
 * the order left the building. Keeping them apart is what lets a company answer "who authorized
 * this" and "when did the supplier get it" separately, and it is why `issue` is its own permission
 * rather than a step somebody with `approve` gets for free.
 *
 * AN ISSUED ORDER CANNOT BE AMENDED. PurchaseOrderService::update() refuses outright — the supplier
 * is already acting on the version they hold, and editing our copy afterwards produces two
 * documents that disagree. The remedy is to cancel with a reason and raise a replacement, which
 * leaves both halves on the record.
 *
 * `part_received` AND `received` ARE ARITHMETIC. They are set from the confirmed receipts, never
 * clicked. A person deciding an order is fully received is a person overriding the count, which is
 * why the status graph offers neither as a transition and `refreshReceiptProgress()` owns both.
 *
 * COMPETITION IS JUSTIFIED, NOT ENFORCED. Above the configured threshold an order raised without an
 * enquiry behind it needs a written reason at approval. It is not refused: sole-source and
 * emergency purchases are real, and a control that cannot be explained is a control people route
 * around rather than record.
 */
class PurchaseOrderController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly PurchaseOrderService $orders,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'purchase_order';
    }

    protected function modelClass(): string
    {
        return PurchaseOrder::class;
    }

    protected function routePrefix(): string
    {
        return 'purchase-orders';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'vendor:id,name',
            'project:id,name,number',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        $query->forVendor($request->query('vendor'));

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            'awaiting' => $query->statusKey('submitted'),
            'delivery' => $query->awaitingDelivery(),
            'overdue' => $query->awaitingDelivery()
                ->whereNotNull('required_by')
                ->whereDate('required_by', '<', now()->toDateString()),
            default => $query,
        };

        /** @var LengthAwarePaginator $purchaseOrders */
        $purchaseOrders = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('purchase-orders.index', [
            'purchaseOrders' => $purchaseOrders,
            'statuses' => $this->statuses->optionsFor('purchase_order'),
            'projects' => $this->projectOptions($user),
            'vendors' => $this->vendorOptions($user),
            'views' => [
                'open' => 'Open',
                'awaiting' => 'Waiting on approval',
                'delivery' => 'Awaiting delivery',
                'overdue' => 'Overdue',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'vendor' => $request->query('vendor'),
            ],
            'canCreate' => $user->can('create', PurchaseOrder::class),
        ]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $this->authorize('view', $purchaseOrder);

        $user = $request->user();

        $purchaseOrder->loadMissing([
            'status', 'vendor', 'project', 'orgUnit', 'location', 'contract:id,number,title',
            'quotation:id,number,reference', 'purchaseRequest:id,number,title',
            'creator:id,name', 'submitter:id,name', 'approver:id,name',
            'issuer:id,name', 'items.receiptItems',
            'receipts.status:id,label,color', 'receipts.receiver:id,name',
        ]);

        return view('purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'progress' => $purchaseOrder->receiptProgress(),
            'timeline' => $this->activity->timelineFor($purchaseOrder)->limit(25)->get(),
            'transitions' => $this->statuses->available('purchase_order', $purchaseOrder->status_id, $user),
            'workflowInstance' => $purchaseOrder->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $purchaseOrder),
            'canDelete' => $user->can('delete', $purchaseOrder),
            'canSubmit' => $user->can('submit', $purchaseOrder),
            'canApprove' => $user->can('approve', $purchaseOrder),
            'canReject' => $user->can('reject', $purchaseOrder),
            'canIssue' => $user->can('issue', $purchaseOrder),
            'canCancel' => $user->can('manage', $purchaseOrder),
            // The next link in the chain. Offered only while the order can still take a delivery,
            // and only to somebody who may book one against THIS order.
            'canReceive' => $purchaseOrder->acceptsReceipts()
                && $user->can('createFor', [GoodsReceipt::class, $purchaseOrder]),
            // Whether approval will demand a written reason, so the approver is told before they
            // click rather than by a validation error afterwards. The rule itself is enforced in
            // the service; this only decides what the form says (§84).
            'needsJustification' => $purchaseOrder->needsCompetitionJustification(
                $this->threshold($purchaseOrder->organization_id),
            ),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PurchaseOrder::class);

        $user = $request->user();

        // Raised from the winning quotation nine times out of ten. Resolved through the scoped
        // query so an id from another tenant prefills nothing (§75, §84).
        $quotation = null;

        if ($quotationId = (int) $request->query('quotation', 0)) {
            $quotation = $this->compiler->apply(Quotation::query(), $user, 'quotation.view')
                ->with('items', 'vendor', 'rfq')
                ->find($quotationId);
        }

        return view('purchase-orders.create', [
            'purchaseOrder' => new PurchaseOrder([
                'confidentiality' => Confidentiality::Internal->value,
                'vendor_id' => $quotation?->vendor_id,
                'quotation_id' => $quotation?->wasAwarded() ? $quotation->id : null,
                'currency' => $quotation?->currency,
                'ordered_on' => now()->toDateString(),
                'project_id' => (int) $request->query('project', 0) ?: null,
            ]),
            'quotation' => $quotation?->wasAwarded() ? $quotation : null,
            ...$this->formOptions($request),
        ]);
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $data = $request->purchaseOrderAttributes();

        $this->assertPlacementIsReachable($data);

        $user = $request->user();

        // From a quotation, the order copies the supplier, the currency, the rate and the lines,
        // and records on the enquiry that an order was raised. The quotation is re-read through the
        // scoped query and authorized rather than trusted from the posted id (§84, §94).
        if ($quotationId = $data['quotation_id'] ?? null) {
            $quotation = $this->compiler->apply(Quotation::query(), $user, 'quotation.view')
                ->with('items')
                ->find($quotationId);

            if ($quotation === null) {
                return back()->withInput()->withErrors(['quotation_id' => 'That quotation could not be found.']);
            }

            $this->authorize('view', $quotation);

            try {
                $purchaseOrder = $this->orders->fromQuotation($quotation, $data, $user);
            } catch (\DomainException $e) {
                return back()->withInput()->withErrors(['quotation_id' => $e->getMessage()]);
            }

            return redirect($this->showRoute($purchaseOrder))
                ->with('status', $purchaseOrder->displayName().' has been raised from '.$quotation->number.'.');
        }

        try {
            $purchaseOrder = $this->orders->create($data, $user);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['vendor_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($purchaseOrder))
            ->with('status', $purchaseOrder->displayName().' has been raised.');
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $this->authorize('update', $purchaseOrder);

        $purchaseOrder->loadMissing('items');

        return view('purchase-orders.edit', [
            'purchaseOrder' => $purchaseOrder,
            'quotation' => null,
            ...$this->formOptions($request),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        $data = $request->purchaseOrderAttributes();

        $this->assertPlacementIsReachable($data);

        try {
            $this->orders->update($purchaseOrder, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['vendor_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($purchaseOrder))
            ->with('status', $purchaseOrder->displayName().' has been updated.');
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('delete', $purchaseOrder);

        $name = $purchaseOrder->displayName();

        $this->recordDeletion($purchaseOrder, $request->user(), 'purchase order');

        $purchaseOrder->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ decisions

    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('submit', $purchaseOrder);

        try {
            $this->orders->submit($purchaseOrder, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseOrder->displayName().' has been submitted for approval.');
    }

    public function approve(ProcurementDecisionRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('approve', $purchaseOrder);

        try {
            $this->orders->approve($purchaseOrder, $request->user(), $request->comment());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseOrder->displayName().' has been approved.');
    }

    public function reject(ProcurementDecisionRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('reject', $purchaseOrder);

        try {
            $this->orders->reject($purchaseOrder, $request->user(), $request->reason());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseOrder->displayName().' has been rejected.');
    }

    /**
     * Send it to the supplier.
     *
     * The moment that closes the requisition end of the chain: the service marks any purchase
     * request behind this order as ordered, which is why that status has no button of its own.
     */
    public function issue(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('issue', $purchaseOrder);

        try {
            $this->orders->issue($purchaseOrder, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseOrder->displayName().' has been issued to the supplier.');
    }

    public function cancel(ProcurementDecisionRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('manage', $purchaseOrder);

        try {
            $this->orders->cancel($purchaseOrder, $request->user(), $request->reason());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseOrder->displayName().' has been cancelled.');
    }

    public function transition(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        return $this->moveStatus($request, $purchaseOrder);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'vendors' => $this->vendorOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            // Only awarded quotations. Ordering against a losing bid is not a thing anybody means
            // to do, and the service refuses it regardless of what the picker offered.
            'quotations' => $this->compiler->apply(Quotation::query(), $user, 'quotation.view')
                ->statusKey('accepted')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'reference', 'vendor_id'])
                ->mapWithKeys(fn (Quotation $q) => [$q->id => $q->number.($q->reference ? ' — '.$q->reference : '')])
                ->all(),
            'purchaseRequests' => $this->compiler->apply(PurchaseRequest::query(), $user, 'purchase_request.view')
                ->statusKey('approved')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'title'])
                ->mapWithKeys(fn (PurchaseRequest $p) => [$p->id => $p->number.' — '.$p->title])
                ->all(),
            // Supply contracts only. A client contract has nothing to do with an order we place,
            // and the service checks the counterparty matches the supplier anyway.
            'contracts' => $this->compiler->apply(Contract::query(), $user, 'contract.view')
                ->where('direction', 'vendor')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'title'])
                ->mapWithKeys(fn (Contract $c) => [$c->id => $c->number.' — '.$c->title])
                ->all(),
            'nextNumber' => $this->numbers->preview('purchase_order'),
        ];
    }

    /** @return array<int,string> */
    private function vendorOptions(User $user): array
    {
        return $this->compiler->apply(Vendor::query(), $user, 'vendor.view')
            ->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }

    /**
     * The figure above which an uncompeted order has to be explained, or null when the company
     * never requires competition. Blank and "no threshold" are the same answer, and the setting
     * stores a string precisely so that blank stays available.
     */
    private function threshold(?int $organizationId): ?string
    {
        $value = $this->settings->string('procurement.rfq_required_above', '', $organizationId);

        return $value === '' ? null : $value;
    }
}
