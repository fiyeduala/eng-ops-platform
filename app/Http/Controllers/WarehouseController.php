<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\WarehouseRequest;
use App\Models\Location;
use App\Models\Status;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The stores themselves.
 *
 * A SMALL REGISTER THAT EVERYTHING ELSE DEPENDS ON. There are usually four or five of these in a
 * company and they change twice a year, which is why the index carries no view tabs and no search
 * beyond the common ones — a filter set built for four hundred rows on a register that holds five
 * is furniture nobody uses.
 *
 * THE SHOW PAGE IS THE STOCK REPORT. What people actually come here for is "what is in this store",
 * so the page leads with the balances rather than with the store's own fields. The value column is
 * behind `stock.view_value`, which is a genuinely separate question from "may you see the store" —
 * a storekeeper counts and issues without needing to know what the shelf is worth.
 *
 * SUSPEND AND CLOSE ARE NOT `update`. Each is its own act with its own refusal — a store cannot be
 * closed while it still holds stock — and WarehouseService owns both. The controller reports what
 * it is told (§84).
 */
class WarehouseController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly WarehouseService $warehouses,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'warehouse';
    }

    protected function modelClass(): string
    {
        return Warehouse::class;
    }

    protected function routePrefix(): string
    {
        return 'warehouses';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Warehouse::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'orgUnit:id,name',
            'keeper:id,name',
        ])->withCount('balances');

        $this->applyCommonFilters($query, $request);

        if ($type = (string) $request->query('type', '')) {
            $query->where('type', $type);
        }

        /** @var LengthAwarePaginator $warehouses */
        $warehouses = $query->orderBy('code')->paginate(30)->withQueryString();

        return view('warehouses.index', [
            'warehouses' => $warehouses,
            'statuses' => $this->statuses->optionsFor('warehouse'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('warehouse.types', Warehouse::DEFAULT_TYPES),
            'filters' => [
                ...$this->commonFilters($request, defaultView: 'all'),
                'type' => $request->query('type'),
            ],
            'canCreate' => $user->can('create', Warehouse::class),
        ]);
    }

    public function show(Request $request, Warehouse $warehouse): View
    {
        $this->authorize('view', $warehouse);

        $user = $request->user();

        $warehouse->loadMissing([
            'status', 'project', 'orgUnit', 'location', 'keeper:id,name', 'creator:id,name',
        ]);

        // The stock report. `stockItem` is eager-loaded because the table reads its name, code and
        // unit on every row, and this page is the one place in the platform where a lazy load would
        // be two hundred queries rather than two.
        $balances = StockBalance::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->with('stockItem:id,code,name,uom,reorder_level,status_id')
            ->where(fn ($query) => $query->where('quantity_on_hand', '!=', 0)
                ->orWhere('quantity_reserved', '!=', 0))
            ->orderBy('stock_item_id')
            ->paginate(50, ['*'], 'balances')
            ->withQueryString();

        return view('warehouses.show', [
            'warehouse' => $warehouse,
            'balances' => $balances,
            'timeline' => $this->activity->timelineFor($warehouse)->limit(25)->get(),
            'transitions' => $this->statuses->available('warehouse', $warehouse->status_id, $user),
            'canEdit' => $user->can('update', $warehouse),
            'canDelete' => $user->can('delete', $warehouse),
            'canSuspend' => $user->can('suspend', $warehouse),
            'canSeeValue' => $user->can('viewValue', StockBalance::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Warehouse::class);

        return view('warehouses.create', [
            'warehouse' => new Warehouse([
                'confidentiality' => Confidentiality::Internal->value,
                'allow_negative_stock' => false,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $this->authorize('create', Warehouse::class);

        $data = $request->warehouseAttributes();

        $this->assertPlacementIsReachable($data);

        $warehouse = $this->warehouses->create($data, $request->user());

        return redirect($this->showRoute($warehouse))
            ->with('status', $warehouse->name.' has been added.');
    }

    public function edit(Request $request, Warehouse $warehouse): View
    {
        $this->authorize('update', $warehouse);

        return view('warehouses.edit', [
            'warehouse' => $warehouse,
            ...$this->formOptions($request),
        ]);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('update', $warehouse);

        $data = $request->warehouseAttributes();

        $this->assertPlacementIsReachable($data);

        $this->warehouses->update($warehouse, $data, $request->user());

        return redirect($this->showRoute($warehouse))
            ->with('status', $warehouse->name.' has been updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('delete', $warehouse);

        $name = $warehouse->name;

        $this->recordDeletion($warehouse, $request->user(), 'store');

        $warehouse->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ standing

    public function activate(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('update', $warehouse);

        $this->warehouses->activate($warehouse, $request->user());

        return back()->with('status', $warehouse->name.' is now open for stock movements.');
    }

    public function suspend(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('suspend', $warehouse);

        try {
            $this->warehouses->suspend($warehouse, $request->user(), $request->input('reason', $request->input('comment')));
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Stock movements at '.$warehouse->name.' have been frozen.');
    }

    public function close(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('manage', $warehouse);

        try {
            $this->warehouses->close($warehouse, $request->user(), $request->input('reason', $request->input('comment')));
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $warehouse->name.' has been closed.');
    }

    /**
     * The generic status move, with one door bolted shut.
     *
     * `close()` refuses while stock is on hand, and the status graph offers active → closed like
     * any other transition — so posting that transition through the generic handler would walk
     * straight past the check and leave a closed store holding four hundred metres of cable. Every
     * route to `closed` therefore goes through the service, whichever button was pressed (§84).
     */
    public function transition(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $target = Status::query()
            ->forEntity('warehouse')
            ->whereKey((int) $request->input('status_id'))
            ->value('key');

        if ($target === 'closed') {
            return $this->close($request, $warehouse);
        }

        return $this->moveStatus($request, $warehouse);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'keepers' => $this->userOptions($user),
            'types' => $this->lists->for('warehouse.types', Warehouse::DEFAULT_TYPES),
        ];
    }
}
