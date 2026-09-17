<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\AssetCustodyTransferRequest;
use App\Http\Requests\AssetRequest;
use App\Models\Asset;
use App\Models\AssetCustodyTransfer;
use App\Models\AssetMaintenance;
use App\Models\GoodsReceiptItem;
use App\Models\Location;
use App\Models\Status;
use App\Services\AssetService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The register of singular things the company owns.
 *
 * THE REGISTER OPENS ON WHAT IS IN USE, not on everything, and the second tab is `unassigned` —
 * because the question an asset controller actually walks up to the screen with is "what is nobody
 * answerable for". A register that opens on four hundred rows sorted by code answers "what exists",
 * which nobody was asking.
 *
 * CUSTODY IS NOT AN EDIT AND HAS ITS OWN ROUTE. `transfer()` below is the only door to
 * AssetService::transferCustody(), which writes the handover row and the four cached columns in one
 * transaction; the edit form cannot reach any of them, and neither can a crafted payload, because
 * they are on the service's deny list as well (§84). The distinction is the whole control on an
 * asset register: somebody who may correct a serial number is not thereby somebody who may move a
 * generator to another yard.
 *
 * `retire()` IS NOT `destroy()`. Retiring needs `asset.dispose`, hands custody back through the
 * ledger first and keeps the row for ever, because a disposed asset is most of what an auditor asks
 * about. Deleting is only ever for the row typed in error an hour ago, and AssetPolicy refuses it
 * outright once a handover trail exists.
 */
class AssetController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly AssetService $assets,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'asset';
    }

    protected function modelClass(): string
    {
        return Asset::class;
    }

    protected function routePrefix(): string
    {
        return 'assets';
    }

    /**
     * The tab strip.
     *
     * `unassigned` is second rather than last on purpose — see the class docblock.
     *
     * @return array<string,string>
     */
    private function views(): array
    {
        return [
            'in_use' => 'In use',
            'unassigned' => 'Held by nobody',
            'maintenance' => 'Under maintenance',
            'vehicles' => 'Vehicles',
            'retired' => 'Retired and lost',
            'all' => 'Everything',
        ];
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Asset::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'custodian:id,name',
            'project:id,name,number',
            'orgUnit:id,name',
            'location:id,name',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($category = (string) $request->query('category', '')) {
            $query->category($category);
        }

        $view = (string) $request->query('view', 'in_use');

        $this->applyView($query, $view);

        /** @var LengthAwarePaginator $assets */
        $assets = $query->orderBy('code')->paginate(30)->withQueryString();

        return view('assets.index', [
            'assets' => $assets,
            'statuses' => $this->statuses->optionsFor('asset'),
            'projects' => $this->projectOptions($user),
            'categories' => $this->lists->for('asset.categories', Asset::DEFAULT_CATEGORIES),
            'views' => $this->views(),
            'filters' => [
                ...$this->commonFilters($request, defaultView: 'in_use'),
                'category' => $request->query('category'),
            ],
            'canCreate' => $user->can('create', Asset::class),
        ]);
    }

    /** @param  Builder<Asset>  $query */
    private function applyView(Builder $query, string $view): void
    {
        match ($view) {
            // "In use" means the working fleet: on a job, standing idle, or on a bench. It
            // deliberately includes idle plant, because a forklift with nothing to lift this month
            // is still a thing the company owns and insures.
            'in_use' => $query->statusKey(['in_service', 'idle', 'under_maintenance']),
            'unassigned' => $query->unassigned()->statusKey(['draft', 'in_service', 'idle', 'under_maintenance']),
            'maintenance' => $query->statusKey('under_maintenance'),
            'vehicles' => $query->vehicles(),
            'retired' => $query->statusKey(['retired', 'lost']),
            default => null,
        };
    }

    public function show(Request $request, Asset $asset): View
    {
        $this->authorize('view', $asset);

        $user = $request->user();

        $asset->loadMissing([
            'status', 'custodian:id,name', 'project:id,name,number', 'orgUnit:id,name',
            'location:id,name', 'vendor:id,name', 'creator:id,name', 'vehicle',
        ]);

        // The handover trail, newest first — the record of account behind the four cached columns
        // above it. Loaded rather than paginated: an asset with more than fifty handovers is rare
        // enough that the whole trail on one page is the right answer, and `custodyMatchesTrail()`
        // needs the collection anyway.
        $asset->load(['custodyTransfers' => fn ($q) => $q->with([
            'fromCustodian:id,name', 'toCustodian:id,name',
            'fromProject:id,name', 'toProject:id,name',
            'fromOrgUnit:id,name', 'toOrgUnit:id,name',
            'fromLocation:id,name', 'toLocation:id,name',
            'authoriser:id,name', 'creator:id,name',
        ])->latest('transferred_at')->latest('id')->limit(50)]);

        $maintenances = $this->compiler
            ->apply($asset->maintenances()->getQuery(), $user, 'asset_maintenance.view')
            ->with('status:id,label,color,is_terminal')
            ->orderByDesc('due_on')
            ->limit(20)
            ->get();

        return view('assets.show', [
            'asset' => $asset,
            'maintenances' => $maintenances,
            'timeline' => $this->activity->timelineFor($asset)->limit(25)->get(),
            'transitions' => $this->statuses->available('asset', $asset->status_id, $user),
            'canEdit' => $user->can('update', $asset),
            'canDelete' => $user->can('delete', $asset),
            'canTransfer' => $user->can('transfer', $asset),
            'canDispose' => $user->can('dispose', $asset),
            'canSeeMaintenance' => $user->can('viewAny', AssetMaintenance::class),
            ...$this->transferOptions($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Asset::class);

        return view('assets.create', [
            'asset' => new Asset(['confidentiality' => Confidentiality::Internal->value]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(AssetRequest $request): RedirectResponse
    {
        $this->authorize('create', Asset::class);

        $asset = $this->assets->create($request->assetAttributes(), $request->user());

        return redirect($this->showRoute($asset))
            ->with('status', $asset->code.' has been added to the register. Hand it to somebody to start its custody trail.');
    }

    public function edit(Request $request, Asset $asset): View
    {
        $this->authorize('update', $asset);

        return view('assets.edit', [
            'asset' => $asset,
            ...$this->formOptions($request),
        ]);
    }

    public function createFromReceipt(Request $request, GoodsReceiptItem $goodsReceiptItem): View
    {
        $this->authorizeReceiptRegistration($goodsReceiptItem);

        return view('assets.create', [
            'asset' => new Asset([
                'name' => mb_substr((string) $goodsReceiptItem->purchaseOrderItem?->description, 0, 300),
                'confidentiality' => $goodsReceiptItem->purchaseOrderItem?->purchaseOrder?->confidentiality ?? Confidentiality::Internal,
            ]),
            'receiptItem' => $goodsReceiptItem,
            ...$this->formOptions($request),
        ]);
    }

    public function storeFromReceipt(AssetRequest $request, GoodsReceiptItem $goodsReceiptItem): RedirectResponse
    {
        $this->authorizeReceiptRegistration($goodsReceiptItem);

        try {
            $asset = $this->assets->registerFromReceipt($goodsReceiptItem, $request->assetAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect($this->showRoute($asset))->with('status', $asset->code.' has been registered from the delivery.');
    }

    private function authorizeReceiptRegistration(GoodsReceiptItem $line): void
    {
        $this->authorize('create', Asset::class);
        $this->authorize('view', $line);
        $line->loadMissing(['goodsReceipt.status', 'purchaseOrderItem.purchaseOrder']);
        abort_if($line->purchaseOrderItem?->purchaseOrder === null, 404);
        $this->authorize('view', $line->purchaseOrderItem->purchaseOrder);
    }

    public function update(AssetRequest $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);

        $this->assets->update($asset, $request->assetAttributes(), $request->user());

        return redirect($this->showRoute($asset))
            ->with('status', $asset->code.' has been updated.');
    }

    public function destroy(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('delete', $asset);

        $name = $asset->displayName();

        $this->recordDeletion($asset, $request->user(), 'asset');

        $asset->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ custody

    /**
     * Hand it over. The ONLY route that changes who is answerable for an asset.
     *
     * The destination is validated by the form request and the placement is checked against THIS
     * user's reach before anything is written — a person whose scope is one department must not be
     * able to push a generator into a project they cannot see, which would make it visible to that
     * project and invisible to them (§84, §94).
     */
    public function transfer(AssetCustodyTransferRequest $request, Asset $asset): RedirectResponse
    {
        $this->authorize('transfer', $asset);

        $destination = $request->destination();

        $this->assertPlacementIsReachable($destination);

        try {
            $transfer = $this->assets->transferCustody(
                $asset,
                $destination,
                $request->user(),
                $request->input('reason'),
                $request->input('condition'),
                $request->input('remarks'),
                $request->filled('authorised_by') ? (int) $request->input('authorised_by') : null,
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['custodian_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($asset))
            ->with('status', $asset->code.' is now with '.$transfer->toLabel().'.');
    }

    public function commission(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('manage', $asset);

        $this->assets->commission($asset, $request->user());

        return back()->with('status', $asset->code.' is now in the working register.');
    }

    /**
     * The end of its working life.
     *
     * Behind `asset.dispose`, which is neither `edit` nor `delete` — it is the entry that makes an
     * asset stop being counted, and a reason is demanded on the status graph for the same reason.
     */
    public function retire(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('dispose', $asset);

        $validated = $request->validate([
            'disposal_reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->assets->retire(
                $asset,
                $request->user(),
                $validated['disposal_reference'] ?? null,
                $validated['reason'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $asset->code.' has been retired.');
    }

    /**
     * The generic status move, with the disposal door bolted shut.
     *
     * The graph offers `in_service → retired` like any other transition, so posting it through the
     * generic handler would set the status without handing custody back and without stamping the
     * disposal date — leaving a scrapped machine that still appears to be in somebody's hands. Every
     * route to `retired` therefore goes through the service, whichever button was pressed. The same
     * arrangement the stores use for `closed` (§84).
     */
    public function transition(Request $request, Asset $asset): RedirectResponse
    {
        $target = Status::query()
            ->forEntity('asset')
            ->whereKey((int) $request->input('status_id'))
            ->value('key');

        if ($target === 'retired') {
            return $this->retire($request, $asset);
        }

        return $this->moveStatus($request, $asset);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            'confidentialities' => Confidentiality::options(),
            'categories' => $this->lists->for('asset.categories', Asset::DEFAULT_CATEGORIES),
            'vendors' => $this->partyOptions($user)['vendors'],
        ];
    }

    /**
     * Everything the handover form on the show page needs.
     *
     * `requiresAuthoriser` is read from settings and passed down so the form can MARK the field
     * required rather than letting somebody fill in six fields and be refused on submit. The check
     * itself stays in the service, where a seeder and an import meet it too.
     *
     * @return array<string,mixed>
     */
    private function transferOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'conditions' => collect(AssetCustodyTransfer::CONDITIONS)
                ->mapWithKeys(fn (string $c) => [$c => ucfirst($c)])->all(),
            'requiresAuthoriser' => $this->settings->bool(
                'asset.require_authoriser_on_transfer',
                true,
                (int) $user->organization_id,
            ),
        ];
    }
}
