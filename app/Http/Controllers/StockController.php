<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\StockCountRequest;
use App\Http\Requests\StockMovementRequest;
use App\Http\Requests\StockTransferRequest;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The ledger. Every movement of every item in every store, in the order it happened.
 *
 * THIS CONTROLLER HAS NO `edit` AND NO `destroy`, and the absence is the design rather than an
 * omission. A stock movement is a statement that something physically happened; correcting it means
 * saying that a different thing happened, which is a new row — `reverse()` — pointing at the one it
 * corrects. The model refuses updates and deletes outright, the policy returns false for both, and
 * PermissionCatalogue does not offer the abilities to grant. Three independent refusals, because
 * this is the register whose integrity everything financial downstream rests on (§59).
 *
 * VALUE IS A SEPARATE PERMISSION FROM THE MOVEMENT. `stock.view_value` gates the cost and value
 * columns everywhere they appear. A store team counts, receives and issues all day without any
 * business knowing the margin on what they are handling, and a system that shows it to them because
 * they can see the quantity has made a decision nobody asked it to make (§68).
 *
 * EACH VERB IS ITS OWN ROUTE AND ITS OWN PERMISSION. Receiving, issuing, transferring, adjusting
 * and reversing are five different acts done by different people, and a single `movements.store`
 * taking a `type` field would collapse them into one grant — the one thing §84 says most plainly
 * not to do.
 */
class StockController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly StockService $stock,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'stock';
    }

    protected function modelClass(): string
    {
        return StockTransaction::class;
    }

    protected function routePrefix(): string
    {
        return 'stock';
    }

    /**
     * Which permission each form demands, and what the page is called.
     *
     * Two maps rather than one nested array, because the first is a security decision and the
     * second is a wording one, and keeping them apart means nobody edits a heading and moves a
     * permission by accident. `create` covers receiving, issuing and returning: they are the
     * ordinary work of a store and a storekeeper who may do one may do all three. Transferring and
     * adjusting are separate grants because they are the two that can move stock without any
     * paperwork arriving to explain it.
     *
     * @var array<string,string>
     */
    private const FORMS = [
        'receive' => 'create',
        'issue' => 'create',
        'return' => 'create',
        'transfer' => 'transfer',
        'count' => 'adjust',
    ];

    /** @var array<string,string> */
    private const HEADINGS = [
        'receive' => 'Receive stock',
        'issue' => 'Issue stock',
        'return' => 'Return stock to the store',
        'transfer' => 'Transfer between stores',
        'count' => 'Record a stock count',
    ];

    // ------------------------------------------------------------------ the ledger

    /**
     * Every movement, newest first, filtered by store, item, type and date.
     *
     * DELIBERATELY NOT USING `applyCommonFilters()`. That helper filters on status, discipline and
     * the register search scope, none of which a stock transaction has: the ledger has no status
     * because a movement is not a thing with a lifecycle, it is a thing that happened. Its filters
     * are the ones a storekeeper actually asks by.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StockTransaction::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'warehouse:id,code,name',
            'stockItem:id,code,name,uom',
            'creator:id,name',
            'project:id,name,number',
        ]);

        if ($warehouseId = (int) $request->query('warehouse', 0)) {
            $query->inWarehouse($warehouseId);
        }

        if ($itemId = (int) $request->query('item', 0)) {
            $query->forItem($itemId);
        }

        if (($type = (string) $request->query('type', '')) !== '' && in_array($type, StockTransaction::TYPES, true)) {
            $query->where('type', $type);
        }

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($from = (string) $request->query('from', '')) {
            $query->whereDate('occurred_at', '>=', $from);
        }

        if ($to = (string) $request->query('to', '')) {
            $query->whereDate('occurred_at', '<=', $to);
        }

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(fn ($q) => $q->where('number', 'like', '%'.$term.'%')
                ->orWhere('reason', 'like', '%'.$term.'%')
                ->orWhere('batch_ref', 'like', '%'.$term.'%'));
        }

        /** @var LengthAwarePaginator $movements */
        $movements = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('stock.index', [
            'movements' => $movements,
            'warehouses' => $this->warehouseOptions(),
            'projects' => $this->projectOptions($user),
            'types' => $this->typeLabels(),
            'filters' => [
                'q' => $request->query('q'),
                'warehouse' => $request->query('warehouse'),
                'item' => $request->query('item'),
                'type' => $request->query('type'),
                'project' => $request->query('project'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
            'canSeeValue' => $user->can('viewValue', StockTransaction::class),
            'canPost' => $user->can('create', StockTransaction::class),
            'canTransfer' => $user->can('transfer', StockTransaction::class),
            'canAdjust' => $user->can('adjust', StockTransaction::class),
        ]);
    }

    public function show(Request $request, StockTransaction $movement): View
    {
        $this->authorize('view', $movement);

        $user = $request->user();

        $movement->loadMissing([
            'warehouse', 'stockItem', 'creator:id,name', 'project:id,name,number',
            'orgUnit:id,name', 'source', 'reverses:id,number,type,quantity',
        ]);

        return view('stock.show', [
            'movement' => $movement,
            'timeline' => $this->activity->timelineFor($movement)->limit(25)->get(),
            'canSeeValue' => $user->can('viewValue', $movement),
            // Refused for a reversal, and for one already reversed. The service says so properly;
            // this only decides whether to draw the button.
            'canReverse' => $user->can('reverse', $movement)
                && $movement->type !== 'reversal'
                && ! $movement->isReversed(),
        ]);
    }

    // ------------------------------------------------------------------ posting

    /**
     * The form for one kind of movement.
     *
     * THE VERB IS IN THE URL AND NOWHERE ELSE. `/stock/new/issue` renders the issue form and
     * authorizes the issue ability before it draws anything; the POST it submits to is a separate
     * endpoint with its own permission check. Nobody can turn a receipt into an issue by editing a
     * field, because there is no field — changing the URL changes which permission is demanded.
     */
    public function create(Request $request, string $verb): View
    {
        if (! array_key_exists($verb, self::FORMS)) {
            abort(404);
        }

        $this->authorize(self::FORMS[$verb], StockTransaction::class);

        $user = $request->user();

        return view('stock.form', [
            'verb' => $verb,
            'heading' => self::HEADINGS[$verb],
            'warehouses' => $this->warehouseOptions(postableOnly: true),
            'items' => $this->itemOptions(),
            'projects' => $this->projectOptions($user),
            'units' => $this->unitOptions($user),
            'canSeeValue' => $user->can('viewValue', StockTransaction::class),
            'defaults' => [
                'warehouse_id' => (int) $request->query('warehouse', 0) ?: null,
                'stock_item_id' => (int) $request->query('item', 0) ?: null,
            ],
        ]);
    }

    public function receive(StockMovementRequest $request): RedirectResponse
    {
        return $this->post($request, 'create', function (StockMovementRequest $request) {
            return $this->stock->receive(
                $this->warehouse($request->validated('warehouse_id')),
                $this->item($request->validated('stock_item_id')),
                (float) $request->validated('quantity'),
                $request->user(),
                $request->movementAttributes(),
            );
        });
    }

    public function issue(StockMovementRequest $request): RedirectResponse
    {
        return $this->post($request, 'create', function (StockMovementRequest $request) {
            return $this->stock->issue(
                $this->warehouse($request->validated('warehouse_id')),
                $this->item($request->validated('stock_item_id')),
                (float) $request->validated('quantity'),
                $request->user(),
                $request->movementAttributes(),
            );
        });
    }

    public function returnToStore(StockMovementRequest $request): RedirectResponse
    {
        return $this->post($request, 'create', function (StockMovementRequest $request) {
            return $this->stock->returnToStore(
                $this->warehouse($request->validated('warehouse_id')),
                $this->item($request->validated('stock_item_id')),
                (float) $request->validated('quantity'),
                $request->user(),
                $request->movementAttributes(),
            );
        });
    }

    /**
     * Two stores, one act. The service writes both rows or neither; this only reports which way it
     * went, and redirects to the OUT row because that is the one the person just caused.
     */
    public function transfer(StockTransferRequest $request): RedirectResponse
    {
        return $this->post($request, 'transfer', function (StockTransferRequest $request) {
            [$out] = $this->stock->transfer(
                $this->warehouse($request->validated('from_warehouse_id')),
                $this->warehouse($request->validated('to_warehouse_id')),
                $this->item($request->validated('stock_item_id')),
                (float) $request->validated('quantity'),
                $request->user(),
                $request->transferAttributes(),
            );

            return $out;
        });
    }

    /**
     * A count, not a difference.
     *
     * The storekeeper types what is on the shelf and the service works out the correction. A form
     * that asked for the correction would be asking somebody holding a clipboard to do arithmetic
     * against a number the system already knows, and every mistake in that subtraction becomes a
     * permanent entry in the ledger. It returns null when the count agrees with the book, which is
     * not a failure — it is the ordinary outcome and worth saying so.
     */
    public function adjust(StockCountRequest $request): RedirectResponse
    {
        $this->authorize('adjust', StockTransaction::class);

        try {
            $movement = $this->stock->adjust(
                $this->warehouse($request->validated('warehouse_id')),
                $this->item($request->validated('stock_item_id')),
                (float) $request->validated('counted_quantity'),
                $request->user(),
                (string) $request->validated('reason'),
                ['notes' => $request->validated('notes')],
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['counted_quantity' => $e->getMessage()]);
        }

        if ($movement === null) {
            return redirect()
                ->route('stock.index', ['warehouse' => $request->validated('warehouse_id')])
                ->with('status', 'The count agreed with the book. Nothing needed correcting.');
        }

        return redirect()->route('stock.show', $movement)
            ->with('status', 'The count was recorded and the difference posted.');
    }

    /**
     * The only way a movement is ever corrected.
     *
     * A written reason is required by the service and by the form, because a reversal with no
     * reason is indistinguishable from stock quietly disappearing, and this is the register an
     * auditor reads first.
     */
    public function reverse(Request $request, StockTransaction $movement): RedirectResponse
    {
        $this->authorize('reverse', $movement);

        $reason = trim((string) $request->input('reason', ''));

        try {
            $reversal = $this->stock->reverse($movement, $request->user(), $reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('stock.show', $reversal)
            ->with('status', $movement->number.' was reversed.');
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Authorize, post, translate a refusal into something readable, redirect to what was written.
     *
     * The five posting verbs differ only in which service method they call and which ability they
     * demand, so the shape they share lives here rather than five times over. The ability is passed
     * in rather than inferred: a helper that worked out the permission from the calling method name
     * would be one rename away from granting the wrong one.
     *
     * @param  callable(mixed):StockTransaction  $post
     */
    private function post(Request $request, string $ability, callable $post): RedirectResponse
    {
        $this->authorize($ability, StockTransaction::class);

        try {
            $movement = $post($request);
        } catch (\DomainException $e) {
            // Against `quantity` because insufficient stock, a closed store and an unstocked item
            // all arrive as an answer about how much of what was asked for, and that is the field
            // the person is looking at.
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('stock.show', $movement)
            ->with('status', $movement->movementLabel().' recorded as '.$movement->number.'.');
    }

    /**
     * A store the reader may actually post into, found within the tenant or not found at all.
     *
     * `findOrFail` under the organization scope answers 404 for another tenant's id, which is the
     * convention across the platform: a 403 would confirm the row exists (§75, §94). The form
     * request has already checked the id belongs here; this is the second lock on the same door,
     * because the request is one way in and this method is what every way in ends at.
     */
    private function warehouse(mixed $id): Warehouse
    {
        return Warehouse::query()->findOrFail((int) $id);
    }

    private function item(mixed $id): StockItem
    {
        return StockItem::query()->findOrFail((int) $id);
    }

    /**
     * Stores that accept movements, for the pickers.
     *
     * Closed and suspended stores are left out rather than shown and refused: offering a choice the
     * service will reject is a form which teaches people to expect errors. They stay visible in the
     * ledger filter, though — history happened in stores that have since closed.
     *
     * @return array<int,string>
     */
    private function warehouseOptions(bool $postableOnly = false): array
    {
        $query = Warehouse::query()->orderBy('code');

        if ($postableOnly) {
            $query->whereHas('status', fn ($q) => $q->where('key', 'active'));
        }

        return $query->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->code.' — '.$w->name])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function itemOptions(): array
    {
        return StockItem::query()
            ->where('is_stock_item', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'uom'])
            ->mapWithKeys(fn (StockItem $i) => [$i->id => $i->code.' — '.$i->name.' ('.$i->uom.')'])
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function typeLabels(): array
    {
        return [
            'receipt' => 'Receipt',
            'issue' => 'Issue',
            'return' => 'Return',
            'transfer_out' => 'Transfer out',
            'transfer_in' => 'Transfer in',
            'adjustment' => 'Adjustment',
            'reversal' => 'Reversal',
        ];
    }
}
