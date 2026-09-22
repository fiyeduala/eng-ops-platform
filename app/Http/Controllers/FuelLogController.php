<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\FuelLogRequest;
use App\Models\FuelLog;
use App\Models\Project;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The fuel book: litres in, money out, and what the fleet is actually costing.
 *
 * NO SHOW PAGE, AND THAT IS THE HONEST SHAPE. A fill is four facts and a receipt number; a page of
 * its own would be four facts and a back button. The register carries everything there is to know
 * about a row, and the vehicle's page carries the last fifteen fills in context, which is where a
 * question about fuel is actually asked. Every other route here — create, edit, delete — lands back
 * on one of those two.
 *
 * NOT A RegisterController for the same reason the fleet is not: a fuel log has no status, no
 * number, no approval and no workflow. It happened or it did not.
 *
 * THE TOTALS ON THE REGISTER ARE FOR THE FILTERED SET, not the page. A litre count that changes when
 * you turn to page two is worse than no total, and a fleet manager filtering to one truck for one
 * month wants the figure for that truck and that month — so the sum is a second aggregate query over
 * the same scoped, filtered builder, run before pagination.
 *
 * DELETING IS ORDINARY HERE, unlike the stock ledger. A fuel log is expenditure, not a balance —
 * nothing reconciles against it, and a receipt entered twice at the pump is a plain mistake with no
 * correcting entry to make. The odometer reading it carried is NOT rolled back, deliberately: the
 * vehicle really did pass that mileage, and winding the clock back would let the next fill be
 * entered below a reading the truck has genuinely done.
 */
class FuelLogController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly FleetService $fleet,
    ) {}

    /** @return array<string,string> */
    private function views(): array
    {
        return [
            'month' => 'This month',
            'quarter' => 'Last 90 days',
            'full_tank' => 'Full fills only',
            'all' => 'Everything',
        ];
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FuelLog::class);

        $user = $request->user();

        $query = $this->compiler
            ->apply(FuelLog::query(), $user, 'fuel_log.view')
            ->with([
                'vehicle:id,registration_number,odometer_unit',
                'driver:id,name',
                'project:id,name,number',
            ]);

        $query->search((string) $request->query('q', ''));
        $query->forVehicle($request->query('vehicle'));

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        $view = (string) $request->query('view', 'month');

        $this->applyView($query, $view);

        // Cloned before pagination so the figures describe the filter rather than the page — see
        // the class docblock. One extra aggregate query, on indexed columns.
        $totals = (clone $query)
            ->reorder()
            ->selectRaw('count(*) as fills, coalesce(sum(litres), 0) as litres, coalesce(sum(total_cost), 0) as spend')
            ->first();

        /** @var LengthAwarePaginator $logs */
        $logs = $query->latest('filled_at')->latest('id')->paginate(40)->withQueryString();

        return view('fuel-logs.index', [
            'logs' => $logs,
            'totals' => [
                'fills' => (int) ($totals->fills ?? 0),
                'litres' => (float) ($totals->litres ?? 0),
                'spend' => (float) ($totals->spend ?? 0),
            ],
            'vehicles' => $this->vehicleOptions($user),
            'projects' => $this->projectOptions($user),
            'views' => $this->views(),
            'filters' => [
                'q' => $request->query('q'),
                'vehicle' => $request->query('vehicle'),
                'project' => $request->query('project'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', FuelLog::class),
        ]);
    }

    /** @param  Builder<FuelLog>  $query */
    private function applyView(Builder $query, string $view): void
    {
        match ($view) {
            'month' => $query->where('filled_at', '>=', now()->startOfMonth()),
            'quarter' => $query->where('filled_at', '>=', now()->subDays(90)),
            'full_tank' => $query->fullTank(),
            default => null,
        };
    }

    public function create(Request $request): View
    {
        $this->authorize('create', FuelLog::class);

        return view('fuel-logs.create', [
            'log' => new FuelLog([
                'is_full_tank' => true,
                'filled_at' => now()->format('Y-m-d\TH:i'),
                // Pre-selected when arriving from a vehicle page, which is where most fills are
                // entered. Re-validated and re-authorised on store — a URL earns nothing (§84).
                'vehicle_id' => $request->query('vehicle'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(FuelLogRequest $request): RedirectResponse
    {
        $this->authorize('create', FuelLog::class);

        $data = $request->fuelAttributes();

        // The fill inherits the vehicle's reach, so it must not be filed against a truck this
        // person cannot open — and the lookup goes through the scoped query rather than findOrFail
        // so a vehicle in another organization is a 404 and not a 403 (§75, §94).
        $vehicle = $this->compiler
            ->apply(Vehicle::query(), $request->user(), 'vehicle.view')
            ->whereKey((int) $data['vehicle_id'])
            ->firstOrFail();

        try {
            $log = $this->fleet->logFuel($vehicle, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['odometer' => $e->getMessage()]);
        }

        return redirect(route('fuel-logs.index', ['vehicle' => $vehicle->getKey()]))
            ->with('status', number_format((float) $log->litres, 2).' litres recorded against '
                .$vehicle->registration_number.'.');
    }

    /**
     * Correct a receipt.
     *
     * The vehicle is not editable — moving a receipt between trucks rewrites two consumption
     * histories, and the second one silently — but everything the paper says is: litres, money, the
     * reading, the station, who was driving, which job it was for.
     */
    public function edit(Request $request, FuelLog $fuelLog): View
    {
        $this->authorize('update', $fuelLog);

        $fuelLog->loadMissing('vehicle:id,registration_number,odometer_unit');

        return view('fuel-logs.edit', [
            'log' => $fuelLog,
            ...$this->formOptions($request),
        ]);
    }

    public function update(FuelLogRequest $request, FuelLog $fuelLog): RedirectResponse
    {
        $this->authorize('update', $fuelLog);

        $data = $request->fuelAttributes();

        $litres = round((float) $data['litres'], 3);
        $total = blank($data['total_cost'] ?? null) ? null : round((float) $data['total_cost'], 2);

        $fuelLog->fill([
            'filled_at' => $data['filled_at'],
            'litres' => $litres,
            'total_cost' => $total,
            // Re-derived rather than carried, so a corrected total never leaves a unit price that
            // no longer divides into it — the receipt is the primary fact (see FuelLog).
            'unit_cost' => $total === null ? null : round($total / $litres, 4),
            'is_full_tank' => (bool) ($data['is_full_tank'] ?? false),
            'odometer' => $data['odometer'] ?? null,
            'station' => $data['station'] ?? null,
            'receipt_reference' => $data['receipt_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
        ]);

        $changed = array_keys($fuelLog->getDirty());

        if ($changed !== []) {
            $fuelLog->save();

            $this->activity->record(
                $fuelLog,
                'updated',
                $request->user()->name.' corrected a fuel record for '
                    .($fuelLog->vehicle?->registration_number ?? 'a vehicle').'.',
                ['fields' => $changed],
                $request->user(),
                $fuelLog->project_id,
            );
        }

        // The vehicle's own reading is a HIGH-WATER MARK, so a correction only ever pushes it
        // forward. A transposed digit corrected downwards fixes the receipt — which is what the
        // consumption arithmetic reads — and leaves the vehicle's clock alone, because the truck
        // really has covered the distance the later fills recorded and winding it back would let
        // the next fill be entered below mileage the vehicle has genuinely done.
        $vehicle = $fuelLog->vehicle;
        $reading = $fuelLog->odometer === null ? null : (int) $fuelLog->odometer;

        if ($vehicle !== null && $reading !== null && $reading > (int) ($vehicle->current_odometer ?? 0)) {
            $this->fleet->recordOdometer($vehicle, $reading);
        }

        return redirect(route('fuel-logs.index', ['vehicle' => $fuelLog->vehicle_id]))
            ->with('status', 'The fuel record has been corrected.');
    }

    public function destroy(Request $request, FuelLog $fuelLog): RedirectResponse
    {
        $this->authorize('delete', $fuelLog);

        $vehicleId = $fuelLog->vehicle_id;

        $this->activity->record(
            $fuelLog,
            'deleted',
            $request->user()->name.' deleted a fuel record of '
                .number_format((float) $fuelLog->litres, 2).' litres.',
            ['litres' => (string) $fuelLog->litres],
            $request->user(),
            $fuelLog->project_id,
        );

        $fuelLog->delete();

        return redirect(route('fuel-logs.index', ['vehicle' => $vehicleId]))
            ->with('status', 'The fuel record has been deleted.');
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            'vehicles' => $this->vehicleOptions($user),
            'projects' => $this->projectOptions($user),
            'drivers' => $this->driverOptions($user),
        ];
    }

    /** @return array<int|string,string> */
    private function vehicleOptions(User $user): array
    {
        // displayName() reads the asset's name, so the asset has to come with the vehicle. Without
        // this the picker lazy-loads once per vehicle — which §89 forbids outright, and which the
        // lazy-loading guard turns into a 500 rather than a slow page.
        return $this->compiler
            ->apply(Vehicle::query(), $user, 'vehicle.view')
            ->with('asset:id,name')
            ->orderBy('registration_number')->limit(500)->get()
            ->mapWithKeys(fn (Vehicle $v) => [$v->id => $v->displayName()])
            ->all();
    }

    /** @return array<int|string,string> */
    private function projectOptions(User $user): array
    {
        return $this->compiler
            ->apply(Project::query(), $user, 'project.view')
            ->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }

    /** @return array<int|string,string> */
    private function driverOptions(User $user): array
    {
        return $this->compiler
            ->apply(User::query()->active(), $user, 'employee.view')
            ->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }
}
