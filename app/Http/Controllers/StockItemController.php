<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\StockItemRequest;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Services\StockItemService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The catalogue: what the company holds, named once so everybody means the same thing.
 *
 * A CATALOGUE ENTRY IS NOT A QUANTITY. This register says what a thing is — its name, its unit,
 * its specification, whether it is hazardous. How much of it exists is a balance, and balances
 * belong to stores. Merging the two is how a company ends up with the same cable catalogued four
 * times because four stores each created their own row for it.
 *
 * THE CODE IS TYPED, NOT GENERATED, which is why this register has no `number` column and its
 * service returns null from `numberColumn()`. An item's identifier is read off a bin card by
 * people who never open this system, so it has to be the word they already use.
 *
 * THE UNIT OF MEASURE BECOMES IMMUTABLE the moment stock has moved in it. StockItemService refuses
 * the change and says why; this controller turns that refusal into a message on the field rather
 * than a page nobody can get past.
 */
class StockItemController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly StockItemService $items,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'stock_item';
    }

    protected function modelClass(): string
    {
        return StockItem::class;
    }

    protected function routePrefix(): string
    {
        return 'stock-items';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StockItem::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)
            ->with(['status:id,label,color,is_terminal'])
            ->withSum('balances as quantity_on_hand', 'quantity_on_hand');

        $this->applyCommonFilters($query, $request);

        if ($category = (string) $request->query('category', '')) {
            $query->category($category);
        }

        $view = (string) $request->query('view', 'all');

        match ($view) {
            'stocked' => $query->stocked(),
            // Below the level somebody set. Items with no level are excluded rather than treated as
            // zero: "no reorder level" means nobody has decided what low looks like for this, and
            // guessing produces an alert list nobody trusts.
            'low' => $query->whereNotNull('reorder_level')
                ->whereHas('balances', fn ($q) => $q->whereColumn('quantity_on_hand', '<=', 'stock_items.reorder_level')),
            default => $query,
        };

        /** @var LengthAwarePaginator $stockItems */
        $stockItems = $query->orderBy('code')->paginate(50)->withQueryString();

        return view('stock-items.index', [
            'stockItems' => $stockItems,
            'statuses' => $this->statuses->optionsFor('stock_item'),
            'categories' => $this->lists->for('stock_item.categories', StockItem::DEFAULT_CATEGORIES),
            'views' => [
                'all' => 'Everything you can see',
                'stocked' => 'Held in stock',
                'low' => 'Below the reorder level',
            ],
            'filters' => [
                ...$this->commonFilters($request, defaultView: 'all'),
                'category' => $request->query('category'),
            ],
            'canCreate' => $user->can('create', StockItem::class),
        ]);
    }

    public function show(Request $request, StockItem $stockItem): View
    {
        $this->authorize('view', $stockItem);

        $user = $request->user();

        $stockItem->loadMissing(['status', 'creator:id,name']);

        $balances = StockBalance::query()
            ->where('stock_item_id', $stockItem->getKey())
            ->with('warehouse:id,code,name,status_id')
            ->orderBy('warehouse_id')
            ->get();

        // The last movements, whatever store they were in. Not paginated: a catalogue page showing
        // the twenty most recent movements answers "has this been moving"; somebody who needs the
        // full history goes to the ledger, which is built for it.
        $movements = $stockItem->transactions()
            ->with(['warehouse:id,code,name', 'creator:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('stock-items.show', [
            'stockItem' => $stockItem,
            'balances' => $balances,
            'movements' => $movements,
            'timeline' => $this->activity->timelineFor($stockItem)->limit(25)->get(),
            'transitions' => $this->statuses->available('stock_item', $stockItem->status_id, $user),
            'canEdit' => $user->can('update', $stockItem),
            'canDelete' => $user->can('delete', $stockItem),
            'canSeeValue' => $user->can('viewValue', StockBalance::class),
        ]);
    }

    // ------------------------------------------------------------------ writing

    public function create(Request $request): View
    {
        $this->authorize('create', StockItem::class);

        return view('stock-items.create', [
            'stockItem' => new StockItem([
                'confidentiality' => Confidentiality::Internal->value,
                'is_stock_item' => true,
                'is_hazardous' => false,
            ]),
            ...$this->formOptions(),
        ]);
    }

    public function store(StockItemRequest $request): RedirectResponse
    {
        $this->authorize('create', StockItem::class);

        $stockItem = $this->items->create($request->stockItemAttributes(), $request->user());

        return redirect($this->showRoute($stockItem))
            ->with('status', $stockItem->name.' has been added to the catalogue.');
    }

    public function edit(Request $request, StockItem $stockItem): View
    {
        $this->authorize('update', $stockItem);

        return view('stock-items.edit', [
            'stockItem' => $stockItem,
            ...$this->formOptions(),
        ]);
    }

    /**
     * The one update in the module that can be refused for a reason nobody typed.
     *
     * Changing the unit of measure after stock has moved in it re-bases every balance ever posted,
     * so the service refuses. The refusal lands on the `uom` field rather than as a flash message,
     * because it is an answer about that field and the person is looking at it.
     */
    public function update(StockItemRequest $request, StockItem $stockItem): RedirectResponse
    {
        $this->authorize('update', $stockItem);

        try {
            $this->items->update($stockItem, $request->stockItemAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['uom' => $e->getMessage()]);
        }

        return redirect($this->showRoute($stockItem))
            ->with('status', $stockItem->name.' has been updated.');
    }

    public function destroy(Request $request, StockItem $stockItem): RedirectResponse
    {
        $this->authorize('delete', $stockItem);

        $name = $stockItem->name;

        $this->recordDeletion($stockItem, $request->user(), 'stock item');

        $stockItem->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ catalogue status

    public function activate(Request $request, StockItem $stockItem): RedirectResponse
    {
        $this->authorize('update', $stockItem);

        $this->items->activate($stockItem, $request->user());

        return back()->with('status', $stockItem->name.' is now in the catalogue.');
    }

    /**
     * Stop using it, without pretending it was never here.
     *
     * Allowed with stock still on the shelf, deliberately: discontinuing is how a company stops
     * BUYING something it still has to use up, and refusing until the balance is zero would mean
     * the last four drums stayed orderable for a year. The service records the quantity that was
     * on hand at the moment it happened, so the decision is readable afterwards.
     */
    public function discontinue(Request $request, StockItem $stockItem): RedirectResponse
    {
        $this->authorize('manage', $stockItem);

        try {
            $this->items->discontinue($stockItem, $request->user(), $request->input('reason'));
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $stockItem->name.' has been discontinued.');
    }

    public function transition(Request $request, StockItem $stockItem): RedirectResponse
    {
        return $this->moveStatus($request, $stockItem);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(): array
    {
        return [
            'categories' => $this->lists->for('stock_item.categories', StockItem::DEFAULT_CATEGORIES),
            'confidentialities' => Confidentiality::options(),
        ];
    }
}
