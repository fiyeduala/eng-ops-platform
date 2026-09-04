<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\GoodsReceiptRequest;
use App\Http\Requests\ProcurementDecisionRequest;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The goods turned up. This is the record that says so, and what was wrong with them.
 *
 * A RECEIPT IS AN OBSERVATION, NOT A DECISION. It records what a storekeeper saw on a particular
 * morning: how many arrived, how many were sent back, what condition they were in, which delivery
 * note came with them. That is why the status graph is short and mostly about who has checked it,
 * and why `disputed` earns its place — goods arrived, they are not what was ordered, and the order
 * must not read as satisfied while that is unresolved.
 *
 * IT MUST BE AGAINST AN ORDER. There is no free-standing receipt: `purchase_order_id` is required,
 * and every line proves it belongs to a line ON THAT ORDER before the service will save it. A
 * posted order-line id from a different order is the obvious way to book somebody else's delivery
 * into your own store (§84, §94).
 *
 * THE BUYER DOES NOT RECEIVE THEIR OWN ORDER. `procurement.separate_buyer_and_receiver` is the
 * second half of segregation of duties, applied in the service and waived — with the waiver
 * recorded — for companies too small to split the two roles.
 *
 * CONFIRMING IS THE POINT OF NO RETURN. Until then the record is somebody typing; after it,
 * `received_at` is stamped, the order recounts itself, and the receipt can no longer be edited. The
 * relation PurchaseOrderItem::receiptItems() tests that column and nothing else, so a half-typed
 * receipt can never move an order to "received in full".
 */
class GoodsReceiptController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly GoodsReceiptService $receipts,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'goods_receipt';
    }

    protected function modelClass(): string
    {
        return GoodsReceipt::class;
    }

    protected function routePrefix(): string
    {
        return 'goods-receipts';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'purchaseOrder:id,number,vendor_id',
            'purchaseOrder.vendor:id,name',
            'location:id,name',
            'receiver:id,name',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        $query->forOrder($request->query('order'));

        if ($locationId = (int) $request->query('location', 0)) {
            $query->where('location_id', $locationId);
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            'disputed' => $query->statusKey('disputed'),
            'awaiting' => $query->statusKey('received'),
            'mine' => $query->where('created_by', $user->getKey()),
            default => $query,
        };

        /** @var LengthAwarePaginator $goodsReceipts */
        $goodsReceipts = $query
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('goods-receipts.index', [
            'goodsReceipts' => $goodsReceipts,
            'statuses' => $this->statuses->optionsFor('goods_receipt'),
            'projects' => $this->projectOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'views' => [
                'open' => 'Open',
                'awaiting' => 'Awaiting inspection',
                'disputed' => 'Disputed',
                'mine' => 'Booked in by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'order' => $request->query('order'),
                'location' => $request->query('location'),
            ],
            'canCreate' => $user->can('create', GoodsReceipt::class),
        ]);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): View
    {
        $this->authorize('view', $goodsReceipt);

        $user = $request->user();

        $goodsReceipt->loadMissing([
            'status', 'location', 'warehouse:id,name', 'receiver:id,name', 'inspector:id,name',
            'creator:id,name',
            'purchaseOrder.vendor:id,name', 'purchaseOrder.status:id,label,color',
            'items.purchaseOrderItem',
        ]);

        return view('goods-receipts.show', [
            'goodsReceipt' => $goodsReceipt,
            'summary' => $goodsReceipt->summary(),
            'timeline' => $this->activity->timelineFor($goodsReceipt)->limit(25)->get(),
            'transitions' => $this->statuses->available('goods_receipt', $goodsReceipt->status_id, $user),
            'workflowInstance' => $goodsReceipt->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $goodsReceipt),
            'canDelete' => $user->can('delete', $goodsReceipt),
            'canConfirm' => $goodsReceipt->isEditable() && $user->can('update', $goodsReceipt),
            'canInspect' => $user->can('update', $goodsReceipt),
            'canApprove' => $user->can('approve', $goodsReceipt),
        ]);
    }

    /**
     * Book a delivery in against an order.
     *
     * The order is required and is resolved through the SCOPED query, then authorized with
     * `createFor`: a storekeeper who may book deliveries in general still must not book one against
     * an order they cannot see, which is what a guessed `?order=` would otherwise achieve.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', GoodsReceipt::class);

        $user = $request->user();

        $order = null;

        if ($orderId = (int) $request->query('order', 0)) {
            $order = $this->compiler->apply(PurchaseOrder::query(), $user, 'purchase_order.view')
                ->with(['items.receiptItems', 'vendor:id,name', 'project:id,name'])
                ->find($orderId);
        }

        if ($order !== null) {
            $this->authorize('createFor', [GoodsReceipt::class, $order]);
        }

        return view('goods-receipts.create', [
            'goodsReceipt' => new GoodsReceipt([
                'confidentiality' => Confidentiality::Internal->value,
                'purchase_order_id' => $order?->id,
                'location_id' => $order?->location_id,
            ]),
            'order' => $order,
            ...$this->formOptions($request),
        ]);
    }

    public function store(GoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $user = $request->user();
        $data = $request->goodsReceiptAttributes();

        $order = $this->compiler->apply(PurchaseOrder::query(), $user, 'purchase_order.view')
            ->find($data['purchase_order_id'] ?? 0);

        if ($order === null) {
            return back()->withInput()->withErrors([
                'purchase_order_id' => 'That purchase order could not be found.',
            ]);
        }

        $this->authorize('createFor', [GoodsReceipt::class, $order]);

        try {
            $goodsReceipt = $this->receipts->create($data, $user);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['purchase_order_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($goodsReceipt))
            ->with('status', $goodsReceipt->displayName().' has been entered against '.$order->number.'.');
    }

    public function edit(Request $request, GoodsReceipt $goodsReceipt): View
    {
        $this->authorize('update', $goodsReceipt);

        $goodsReceipt->loadMissing(
            'items',
            'purchaseOrder.items.receiptItems',
            'purchaseOrder.vendor:id,name',
            'purchaseOrder.project:id,name',
        );

        return view('goods-receipts.edit', [
            'goodsReceipt' => $goodsReceipt,
            'order' => $goodsReceipt->purchaseOrder,
            ...$this->formOptions($request),
        ]);
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        try {
            $this->receipts->update($goodsReceipt, $request->goodsReceiptAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['purchase_order_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($goodsReceipt))
            ->with('status', $goodsReceipt->displayName().' has been updated.');
    }

    public function destroy(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('delete', $goodsReceipt);

        $name = $goodsReceipt->displayName();

        $this->recordDeletion($goodsReceipt, $request->user(), 'goods receipt');

        $goodsReceipt->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ the delivery itself

    /**
     * Say the delivery is real.
     *
     * Stamps `received_at`, which is the one column the order's arithmetic reads, and recounts the
     * order. Everything before this is a draft that changes nothing.
     */
    public function confirm(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        try {
            $this->receipts->confirm($goodsReceipt, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $goodsReceipt->displayName().' has been confirmed.');
    }

    public function inspect(ProcurementDecisionRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        try {
            $this->receipts->inspect($goodsReceipt, $request->user(), $request->comment());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', 'The inspection has been recorded.');
    }

    public function accept(ProcurementDecisionRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('approve', $goodsReceipt);

        try {
            $this->receipts->accept($goodsReceipt, $request->user(), $request->comment());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', $goodsReceipt->displayName().' has been accepted.');
    }

    public function dispute(ProcurementDecisionRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        try {
            $this->receipts->dispute($goodsReceipt, $request->user(), $request->reason());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', 'The dispute has been raised against the supplier.');
    }

    public function transition(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        return $this->moveStatus($request, $goodsReceipt);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'conditions' => $this->lists->for('goods_receipt.conditions', GoodsReceipt::DEFAULT_CONDITIONS),
            'confidentialities' => Confidentiality::options(),
            // Only orders that can still take a delivery. A cancelled or fully received order in
            // the picker is a way to book goods against something nobody is expecting.
            'orders' => $this->compiler->apply(PurchaseOrder::query(), $user, 'purchase_order.view')
                // Through the relation rather than a raw sub-select, so the status table's own
                // tenant scope applies: another company's status row must not widen this list.
                ->whereHas('status', fn ($q) => $q->whereIn('key', ['issued', 'part_received', 'received']))
                ->with('vendor:id,name')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'vendor_id'])
                ->mapWithKeys(fn (PurchaseOrder $o) => [$o->id => $o->number.' — '.($o->vendor?->name ?? 'Supplier')])
                ->all(),
            'nextNumber' => $this->numbers->preview('goods_receipt'),
            /*
             * Only stores this person may see AND that will take goods. A suspended store in the
             * picker is a delivery that will be refused at acceptance, three days after the
             * storekeeper made the choice; a store belonging to somebody else's department that
             * this reader cannot open has no business being read out to them (§61).
             *
             * An empty list is normal — a company that keeps no stores never sees the field.
             */
            'warehouses' => $this->compiler->apply(Warehouse::query(), $user, 'warehouse.view')
                ->acceptingMovements()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->name.' ('.$w->code.')'])
                ->all(),
        ];
    }
}
