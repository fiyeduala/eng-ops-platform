<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\AssetMaintenanceCompletionRequest;
use App\Http\Requests\AssetMaintenanceRequest;
use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Status;
use App\Models\User;
use App\Services\AssetMaintenanceService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Job cards: what is due, what is being worked on, and what it cost.
 *
 * THE REGISTER OPENS ON `overdue`. Every other register in this platform opens on the work
 * outstanding; here the work outstanding has a date attached to it, and a service that slipped three
 * weeks ago is the row somebody needs to see before the one due next month. The tabs after it read
 * in the order a maintenance meeting runs: overdue, due soon, in progress, unplanned, everything.
 *
 * COMPLETING IS ITS OWN ROUTE BEHIND ITS OWN PERMISSION. Certifying that a service happened is the
 * statement "this machine is safe to run again" — read afterwards by an insurer, an inspector and,
 * occasionally, an accident investigation — and it is a genuinely different act from scheduling the
 * work. The status graph gates `in_progress → completed` on `asset_maintenance.complete` and so does
 * this controller, so both doors carry the same lock (§6).
 *
 * `transition()` BOLTS THAT DOOR TOO. The generic status handler authorises `update`, and the graph
 * offers `in_progress → completed` like any other move, so posting the target status directly would
 * close a job card without recording the costs, the downtime or the odometer, and would skip
 * returning the machine to service. Every route to `completed` therefore goes through the service —
 * the same arrangement the stores use for `closed` and the asset register uses for `retired` (§84).
 */
class AssetMaintenanceController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly AssetMaintenanceService $maintenances,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'asset_maintenance';
    }

    protected function modelClass(): string
    {
        return AssetMaintenance::class;
    }

    protected function routePrefix(): string
    {
        return 'asset-maintenances';
    }

    /** @return array<string,string> */
    private function views(): array
    {
        return [
            'overdue' => 'Overdue',
            'due_soon' => 'Due soon',
            'in_progress' => 'In progress',
            'unplanned' => 'Breakdowns',
            'open' => 'All open',
            'all' => 'Everything',
        ];
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AssetMaintenance::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'asset:id,code,name,is_vehicle',
            'performer:id,name',
            'vendor:id,name',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($assetId = (int) $request->query('asset', 0)) {
            $query->forAsset($assetId);
        }

        $view = (string) $request->query('view', 'overdue');

        $this->applyView($query, $view);

        /** @var LengthAwarePaginator $maintenances */
        $maintenances = $query->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('asset-maintenances.index', [
            'maintenances' => $maintenances,
            'statuses' => $this->statuses->optionsFor('asset_maintenance'),
            'projects' => $this->projectOptions($user),
            'assets' => $this->assetOptions($user),
            'views' => $this->views(),
            'filters' => [
                ...$this->commonFilters($request, defaultView: 'overdue'),
                'asset' => $request->query('asset'),
            ],
            'canCreate' => $user->can('create', AssetMaintenance::class),
        ]);
    }

    /** @param  Builder<AssetMaintenance>  $query */
    private function applyView(Builder $query, string $view): void
    {
        match ($view) {
            'overdue' => $query->overdue(),
            // Thirty days rather than a setting, because this is a TAB and not a warning. The
            // warning window that decides who gets an e-mail is per-organization and lives in
            // AssetMaintenanceService::sweepDue(); a person clicking "due soon" is browsing, and a
            // browsing horizon that differs per company for no visible reason is just confusing.
            'due_soon' => $query->dueWithin(30),
            'in_progress' => $query->statusKey(['in_progress', 'on_hold']),
            'unplanned' => $query->unplanned(),
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            default => null,
        };
    }

    public function show(Request $request, AssetMaintenance $assetMaintenance): View
    {
        $this->authorize('view', $assetMaintenance);

        $user = $request->user();

        $assetMaintenance->loadMissing([
            'status', 'asset.vehicle', 'asset.custodian:id,name',
            'performer:id,name', 'vendor:id,name', 'creator:id,name',
        ]);

        return view('asset-maintenances.show', [
            'maintenance' => $assetMaintenance,
            'timeline' => $this->activity->timelineFor($assetMaintenance)->limit(25)->get(),
            'transitions' => $this->statuses->available('asset_maintenance', $assetMaintenance->status_id, $user),
            'canEdit' => $user->can('update', $assetMaintenance),
            'canDelete' => $user->can('delete', $assetMaintenance),
            'canComplete' => $user->can('complete', $assetMaintenance),
            'vendors' => $this->partyOptions($user)['vendors'],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', AssetMaintenance::class);

        return view('asset-maintenances.create', [
            'maintenance' => new AssetMaintenance([
                'confidentiality' => Confidentiality::Internal->value,
                'is_planned' => true,
                // Pre-selected when arriving from an asset page, which is where most job cards are
                // raised. Validated like any other id — arriving in the URL earns it nothing (§84).
                'asset_id' => $request->query('asset'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(AssetMaintenanceRequest $request): RedirectResponse
    {
        $this->authorize('create', AssetMaintenance::class);

        $data = $request->maintenanceAttributes();

        // The job card inherits the asset's reach, so filing one against a machine this person
        // cannot see would create a record they cannot then open (§94).
        $this->authorize('view', Asset::findOrFail($data['asset_id']));

        $maintenance = $this->maintenances->create($data, $request->user());

        return redirect($this->showRoute($maintenance))
            ->with('status', $maintenance->reference.' has been raised.');
    }

    public function edit(Request $request, AssetMaintenance $assetMaintenance): View
    {
        $this->authorize('update', $assetMaintenance);

        return view('asset-maintenances.edit', [
            'maintenance' => $assetMaintenance,
            ...$this->formOptions($request),
        ]);
    }

    public function update(AssetMaintenanceRequest $request, AssetMaintenance $assetMaintenance): RedirectResponse
    {
        $this->authorize('update', $assetMaintenance);

        $this->maintenances->update($assetMaintenance, $request->maintenanceAttributes(), $request->user());

        return redirect($this->showRoute($assetMaintenance))
            ->with('status', $assetMaintenance->reference.' has been updated.');
    }

    public function destroy(Request $request, AssetMaintenance $assetMaintenance): RedirectResponse
    {
        $this->authorize('delete', $assetMaintenance);

        $reference = $assetMaintenance->reference;

        $this->recordDeletion($assetMaintenance, $request->user(), 'job card');

        $assetMaintenance->delete();

        return redirect($this->indexRoute())->with('status', $reference.' has been deleted.');
    }

    // ------------------------------------------------------------------ the job

    public function start(Request $request, AssetMaintenance $assetMaintenance): RedirectResponse
    {
        $this->authorize('update', $assetMaintenance);

        $validated = $request->validate([
            'odometer' => ['nullable', 'integer', 'min:0', 'max:99999999'],
        ]);

        try {
            $this->maintenances->start(
                $assetMaintenance,
                $request->user(),
                isset($validated['odometer']) ? (int) $validated['odometer'] : null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Work has started on '.$assetMaintenance->reference.'.');
    }

    public function complete(AssetMaintenanceCompletionRequest $request, AssetMaintenance $assetMaintenance): RedirectResponse
    {
        $this->authorize('complete', $assetMaintenance);

        try {
            $this->maintenances->complete($assetMaintenance, $request->outcome(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['odometer' => $e->getMessage()]);
        }

        return redirect($this->showRoute($assetMaintenance))
            ->with('status', $assetMaintenance->reference.' has been signed off.');
    }

    /** See the class docblock on why `completed` never reaches the generic handler. */
    public function transition(Request $request, AssetMaintenance $assetMaintenance): RedirectResponse
    {
        $target = Status::query()
            ->forEntity('asset_maintenance')
            ->whereKey((int) $request->input('status_id'))
            ->value('key');

        if ($target === 'completed') {
            $this->authorize('complete', $assetMaintenance);

            return back()->withErrors([
                'status_id' => 'Sign the job card off with its costs and findings rather than moving '
                    .'its status — use "Certify complete" on this page.',
            ]);
        }

        if ($target === 'in_progress' && $assetMaintenance->statusKey() === 'scheduled') {
            return $this->start($request, $assetMaintenance);
        }

        $this->authorize('update', $assetMaintenance);
        if ($target === 'cancelled' || ($target === 'in_progress' && $assetMaintenance->isClosed())) {
            $this->authorize('manage', $assetMaintenance);
        }
        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $to = Status::query()->forEntity('asset_maintenance')->find($validated['status_id']);
        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a maintenance status.']);
        }
        try {
            $this->maintenances->changeStatus($assetMaintenance, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $assetMaintenance->reference.' is now '.$to->label.'.');
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            'assets' => $this->assetOptions($user),
            'people' => $this->userOptions($user),
            'vendors' => $this->partyOptions($user)['vendors'],
            'types' => $this->lists->for('asset_maintenance.types', AssetMaintenance::DEFAULT_TYPES),
            'confidentialities' => Confidentiality::options(),
        ];
    }

    /**
     * Machines this person can reach, for the picker.
     *
     * Retired assets are excluded: scheduling a service on something the company no longer owns is
     * never the intention, and the list is long enough without them. A job card already raised
     * against one is untouched — history is not rewritten by a dropdown.
     *
     * @return array<int|string,string>
     */
    private function assetOptions(User $user): array
    {
        return $this->compiler
            ->apply(Asset::query(), $user, 'asset.view')
            ->whereNull('disposed_on')
            ->orderBy('code')->limit(500)->get()
            ->mapWithKeys(fn (Asset $a) => [$a->id => $a->displayName()])
            ->all();
    }
}
