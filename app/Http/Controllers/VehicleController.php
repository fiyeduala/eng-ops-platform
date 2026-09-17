<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Http\Requests\VehicleRequest;
use App\Models\Asset;
use App\Models\FuelLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\FleetService;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The fleet — which is the asset register wearing a different hat.
 *
 * NOT A RegisterController, and that is the honest shape rather than an omission. A vehicle has no
 * number of its own, no status of its own, no confidentiality of its own and no recycle bin: all
 * five belong to the ASSET it is one-to-one with, because a pickup is bought, capitalised,
 * transferred, maintained and retired exactly like a generator. Inheriting the register base would
 * have meant declaring an entity type, a status vocabulary and a numbering scheme that do not exist,
 * which is how a fleet ends up with custody history that works for cranes and not for lorries (§90).
 *
 * THE REGISTER OPENS ON `papers_due`, and that is deliberate. Everything else here — the plates, the
 * odometers, the fuel — can wait until Thursday. A truck whose insurance lapsed last week is a truck
 * that must not leave the yard, and the exposure if it does is not a maintenance backlog but an
 * uninsured collision with somebody else's car in it.
 *
 * THREE SEPARATE WRITES, THREE SEPARATE RIGHTS. Correcting a tyre size is `vehicle.edit`; naming the
 * driver is `vehicle.assign`, because whose licence is on the line is not the same kind of decision;
 * and moving the odometer goes through FleetService, the single writer of that column, which refuses
 * a reading lower than the one already on the clock. A payload that could set any of the three
 * through the edit form would route around all three (§84).
 */
class VehicleController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly FleetService $fleet,
        private readonly SettingsRepository $settings,
        private readonly OptionList $lists,
    ) {}

    /** @return array<string,string> */
    private function views(): array
    {
        return [
            'papers_due' => 'Papers due',
            'assigned' => 'With a driver',
            'unassigned' => 'No driver',
            'all' => 'Whole fleet',
        ];
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);

        $user = $request->user();

        $query = $this->compiler
            ->apply(Vehicle::query(), $user, 'vehicle.view')
            ->with([
                'asset:id,code,name,status_id,custodian_id,project_id,org_unit_id,is_vehicle',
                'asset.status:id,label,color,is_terminal',
                'asset.custodian:id,name',
                'assignedDriver:id,name',
            ]);

        $query->search((string) $request->query('q', ''));
        $query->type($request->query('type'));

        $view = (string) $request->query('view', 'papers_due');
        $days = $this->settings->int(
            'vehicle_document.expiry_warning_days', 60, (int) $user->organization_id
        );

        $this->applyView($query, $view, $user);

        // How many certificates need attention, for the register's "Papers" column. Counted only for
        // somebody who may list vehicle documents at all, and reported as a NUMBER rather than a
        // list: which certificate has lapsed is on the vehicle's own page behind its own permission,
        // and a count on a register anybody may open must not name it (§61).
        $canSeeDocuments = $user->can('viewAny', VehicleDocument::class);

        if ($canSeeDocuments) {
            $query->withCount(['documents as documents_due_count' => fn ($q) => $q
                ->visibleTo($user)->current()
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->startOfDay()->addDays($days)),
            ]);
        }

        /** @var LengthAwarePaginator $vehicles */
        $vehicles = $query->orderBy('registration_number')->paginate(30)->withQueryString();

        return view('vehicles.index', [
            'vehicles' => $vehicles,
            'types' => $this->lists->for('vehicle.types', Vehicle::DEFAULT_TYPES),
            'views' => $this->views(),
            'filters' => [
                'q' => $request->query('q'),
                'type' => $request->query('type'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Vehicle::class),
            'canSeeDocuments' => $canSeeDocuments,
        ]);
    }

    /**
     * @param  Builder<Vehicle>  $query
     */
    private function applyView(Builder $query, string $view, User $user): void
    {
        match ($view) {
            // The company's own warning window, the same figure the nightly sweep uses — so the tab
            // and the e-mail can never disagree about which trucks are in trouble. Already-expired
            // papers are included, because those are the urgent ones.
            'papers_due' => $query->whereHas('documents', fn ($q) => $q
                ->visibleTo($user)->current()
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->startOfDay()->addDays(
                    $this->settings->int('vehicle_document.expiry_warning_days', 60, (int) $user->organization_id)
                ))),
            'assigned' => $query->whereNotNull('assigned_driver_id'),
            'unassigned' => $query->whereNull('assigned_driver_id'),
            default => null,
        };
    }

    public function show(Request $request, Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);

        $user = $request->user();

        $vehicle->loadMissing([
            'asset.status', 'asset.custodian:id,name', 'asset.project:id,name,number',
            'asset.orgUnit:id,name', 'asset.location:id,name', 'assignedDriver:id,name',
        ]);

        // Both are gated on their own permission rather than on `vehicle.view`, because "may you
        // see the lorry" and "may you see what its insurance cost" are different questions (§61).
        $documents = $user->can('viewAny', VehicleDocument::class)
            ? $this->compiler
                ->apply($vehicle->documents()->getQuery(), $user, 'vehicle_document.view')
                ->orderByRaw('expires_on is null')
                ->orderBy('expires_on')
                ->get()
            : collect();

        $fuelLogs = $user->can('viewAny', FuelLog::class)
            ? $this->compiler
                ->apply($vehicle->fuelLogs()->getQuery(), $user, 'fuel_log.view')
                ->with('driver:id,name')
                ->latest('filled_at')
                ->limit(15)
                ->get()
            : collect();

        return view('vehicles.show', [
            'vehicle' => $vehicle,
            'documents' => $documents,
            'fuelLogs' => $fuelLogs,
            'timeline' => $this->activity->timelineFor($vehicle)->limit(25)->get(),
            // The company's own window, so a certificate flagged amber here is flagged amber by the
            // nightly sweep on the same day.
            'warningDays' => $this->settings->int(
                'vehicle_document.expiry_warning_days', 60, (int) $user->organization_id
            ),
            'canEdit' => $user->can('update', $vehicle),
            'canAssign' => $user->can('assign', $vehicle),
            'canLogFuel' => $user->can('create', FuelLog::class),
            'canFileDocument' => $user->can('create', VehicleDocument::class),
            'drivers' => $this->driverOptions($user),
        ]);
    }

    /**
     * Attach a plate to an asset that has not got one.
     *
     * The picker offers only assets that are not already vehicles — see `attachableAssets()` — so
     * the ordinary path never reaches the service's refusal. The refusal still exists, because a
     * form is not a control.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Vehicle::class);

        return view('vehicles.create', [
            'vehicle' => new Vehicle(['odometer_unit' => 'km']),
            'assetId' => $request->query('asset'),
            ...$this->formOptions($request),
        ]);
    }

    public function store(VehicleRequest $request): RedirectResponse
    {
        $this->authorize('create', Vehicle::class);

        $data = $request->vehicleAttributes();

        // The vehicle inherits the asset's reach, so a plate must not be attached to a machine this
        // person cannot open (§94).
        $asset = Asset::findOrFail($data['asset_id']);
        $this->authorize('view', $asset);

        try {
            $vehicle = $this->fleet->attachVehicle($asset, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['asset_id' => $e->getMessage()]);
        }

        return redirect(route('vehicles.show', $vehicle))
            ->with('status', $vehicle->registration_number.' has joined the fleet.');
    }

    public function edit(Request $request, Vehicle $vehicle): View
    {
        $this->authorize('update', $vehicle);

        $vehicle->loadMissing('asset:id,code,name');

        return view('vehicles.edit', [
            'vehicle' => $vehicle,
            'assetId' => $vehicle->asset_id,
            ...$this->formOptions($request),
        ]);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        $this->fleet->updateVehicle($vehicle, $request->vehicleAttributes(), $request->user());

        return redirect(route('vehicles.show', $vehicle))
            ->with('status', $vehicle->registration_number.' has been updated.');
    }

    // ------------------------------------------------------------------ the driver and the clock

    /**
     * Name the person answerable for the vehicle on the road.
     *
     * Behind `vehicle.assign`, not `vehicle.edit` — see the class docblock. A blank value clears the
     * driver, which is a real act: a lorry between drivers has nobody answerable for it, and
     * recording that honestly is better than leaving the last person's name against it.
     */
    public function assignDriver(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('assign', $vehicle);

        $validated = $request->validate([
            'assigned_driver_id' => ['nullable', 'integer'],
        ]);

        $driverId = blank($validated['assigned_driver_id'] ?? null)
            ? null
            : (int) $validated['assigned_driver_id'];

        // Resolved through the same scoped list the form was populated from, so an id typed into the
        // payload for somebody this person cannot reach resolves to nothing (§84).
        if ($driverId !== null && ! array_key_exists($driverId, $this->driverOptions($request->user()))) {
            return back()->withErrors(['assigned_driver_id' => 'That person is not somebody you can name as a driver.']);
        }

        $this->fleet->assignDriver($vehicle, $driverId, $request->user());

        return back()->with('status', $driverId === null
            ? 'The driver has been cleared from '.$vehicle->registration_number.'.'
            : $vehicle->registration_number.' is now driven by '.($vehicle->assignedDriver?->name ?? 'the named driver').'.');
    }

    /**
     * Move the counter forward.
     *
     * Behind `vehicle.edit` rather than a right of its own: an odometer reading is a correction to a
     * cached figure, and the guard that matters — it never goes backwards — is in the service, where
     * a fill and a completed service meet it too.
     */
    public function recordOdometer(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        $validated = $request->validate([
            'current_odometer' => ['required', 'integer', 'min:0', 'max:99999999'],
        ]);

        try {
            $this->fleet->recordOdometer($vehicle, (int) $validated['current_odometer']);
        } catch (\DomainException $e) {
            return back()->withErrors(['current_odometer' => $e->getMessage()]);
        }

        return back()->with('status', 'The odometer on '.$vehicle->registration_number.' now reads '
            .number_format((float) $vehicle->current_odometer).' '.$vehicle->odometer_unit.'.');
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            'assets' => $this->attachableAssets($user),
            'types' => $this->lists->for('vehicle.types', Vehicle::DEFAULT_TYPES),
            'fuelTypes' => $this->lists->for('vehicle.fuel_types', Vehicle::DEFAULT_FUEL_TYPES),
            'odometerUnits' => [
                'km' => 'Kilometres',
                'mi' => 'Miles',
                'hours' => 'Running hours (plant)',
            ],
        ];
    }

    /**
     * Assets that could become vehicles: reachable, not disposed of, not already plated.
     *
     * @return array<int|string,string>
     */
    private function attachableAssets(User $user): array
    {
        return $this->compiler
            ->apply(Asset::query(), $user, 'asset.view')
            ->where('is_vehicle', false)
            ->whereNull('disposed_on')
            ->orderBy('code')->limit(500)->get()
            ->mapWithKeys(fn (Asset $a) => [$a->id => $a->displayName()])
            ->all();
    }

    /**
     * Who may be named as a driver.
     *
     * Scoped on `employee.view` like every other "name a colleague" field, rather than on
     * `user.view` — which is the administrative permission covering accounts and lockouts and would
     * leave the field empty for exactly the people who use it (§61).
     *
     * @return array<int|string,string>
     */
    private function driverOptions(User $user): array
    {
        return $this->compiler
            ->apply(User::query()->active(), $user, 'employee.view')
            ->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }
}
