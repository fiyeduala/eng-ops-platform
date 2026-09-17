<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Models\Asset;
use App\Models\FuelLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\OptionList;
use Illuminate\Support\Facades\DB;

/**
 * The engine half of an asset: plates, odometers and fuel.
 *
 * NOT A RegisterService, AND THAT IS THE DESIGN RATHER THAN AN OMISSION. A vehicle has no number of
 * its own (the state issued its plate), no status of its own (the ASSET is in service, idle,
 * retired or lost), no confidentiality of its own and no soft deletes. It is one-to-one with an
 * asset — see the migration — so everything a register gives a record, a vehicle already has
 * through the thing it is attached to. Making it a register would duplicate custody, acquisition
 * and retirement in a second place that would quietly drift from the first (§90).
 *
 * THE ODOMETER ONLY GOES FORWARD, and `recordOdometer()` is the only thing that moves it. That
 * single rule is what makes every downstream figure meaningful: consumption is litres over distance,
 * a service due at 20,000km is a comparison, and both are nonsense the moment a reading can go
 * backwards. A transposed reading — 41,000 typed as 14,000 — would otherwise show a truck that
 * travelled minus 27,000km and drank 40 litres doing it.
 *
 * REFUSED, NOT SILENTLY IGNORED. A backwards reading is somebody's typing mistake and the person
 * who made it is standing there; telling them beats storing a number the fleet manager will
 * disbelieve in six months with nobody left to ask. The refusal names both figures so the
 * correction is obvious.
 *
 * FUEL DOES NOT MOVE STOCK. A litre bought at a filling station never entered a warehouse, has no
 * balance to decrement and is expenditure rather than inventory. A company that issues diesel from
 * its OWN bulk tank has a stock item and posts an issue through StockService, and the fuel log then
 * records the same litres going into the vehicle — two facts, both true, deliberately not one
 * mechanism pretending to be both.
 */
class FleetService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly OptionList $options,
    ) {}

    // ================================================================== the vehicle

    /**
     * Give an existing asset a plate, a tank and an odometer.
     *
     * The asset comes first and always: a truck is registered on the asset register like everything
     * else, and this attaches the extra page. `is_vehicle` is flipped here rather than being a form
     * field, because it is the flag that decides whether this row may exist at all — see
     * AssetService::protectedAttributes().
     *
     * @param  array<string,mixed>  $attributes
     */
    public function attachVehicle(Asset $asset, array $attributes, User $actor): Vehicle
    {
        $asset->loadMissing('vehicle');

        if ($asset->vehicle !== null) {
            throw new \DomainException(
                $asset->code.' is already registered as a vehicle ('
                .$asset->vehicle->registration_number.').'
            );
        }

        return DB::transaction(function () use ($asset, $attributes, $actor) {
            $vehicle = new Vehicle([
                'vin' => $attributes['vin'] ?? null,
                'year_of_manufacture' => $attributes['year_of_manufacture'] ?? null,
                'colour' => $attributes['colour'] ?? null,
                'tank_capacity' => $attributes['tank_capacity'] ?? null,
            ]);

            $vehicle->organization_id = $asset->organization_id;
            $vehicle->asset_id = $asset->getKey();
            $vehicle->registration_number = $this->normalisePlate($attributes['registration_number'] ?? null);
            $vehicle->vehicle_type = $this->option('vehicle.types', Vehicle::DEFAULT_TYPES, $attributes['vehicle_type'] ?? null, $actor);
            $vehicle->fuel_type = $this->option('vehicle.fuel_types', Vehicle::DEFAULT_FUEL_TYPES, $attributes['fuel_type'] ?? null, $actor);
            $vehicle->odometer_unit = in_array($attributes['odometer_unit'] ?? null, Vehicle::ODOMETER_UNITS, true)
                ? $attributes['odometer_unit']
                : 'km';

            // The opening reading, and the only time a figure lands on this column without going
            // through the monotonicity guard — because there is nothing yet for it to go backwards
            // from. A vehicle bought second-hand starts wherever its clock says.
            $vehicle->current_odometer = blank($attributes['current_odometer'] ?? null)
                ? null
                : max(0, (int) $attributes['current_odometer']);

            $vehicle->save();

            $asset->forceFill(['is_vehicle' => true])->save();

            $this->activity->record(
                $asset,
                'registered_as_vehicle',
                $actor->name.' registered '.$asset->code.' as vehicle '.$vehicle->registration_number.'.',
                ['registration_number' => $vehicle->registration_number],
                $actor,
            );

            return $vehicle;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function updateVehicle(Vehicle $vehicle, array $attributes, User $actor): Vehicle
    {
        // `current_odometer` and `assigned_driver_id` are absent on purpose: one goes through the
        // guard below, the other through `assignDriver()` behind its own permission. A payload that
        // could set either would route around both (§84).
        $vehicle->fill(array_intersect_key($attributes, array_flip([
            'vin', 'year_of_manufacture', 'colour', 'tank_capacity',
        ])));

        if (array_key_exists('registration_number', $attributes)) {
            $vehicle->registration_number = $this->normalisePlate($attributes['registration_number']);
        }

        foreach ([
            'vehicle_type' => ['vehicle.types', Vehicle::DEFAULT_TYPES],
            'fuel_type' => ['vehicle.fuel_types', Vehicle::DEFAULT_FUEL_TYPES],
        ] as $column => [$key, $fallback]) {
            if (array_key_exists($column, $attributes)) {
                $vehicle->{$column} = $this->option($key, $fallback, $attributes[$column], $actor);
            }
        }

        if (! $vehicle->isDirty()) {
            return $vehicle;
        }

        $changed = array_keys($vehicle->getDirty());
        $vehicle->save();

        $this->activity->record(
            $vehicle,
            'updated',
            $actor->name.' updated vehicle '.$vehicle->registration_number.'.',
            ['fields' => $changed],
            $actor,
        );

        return $vehicle;
    }

    /**
     * Name the person answerable for the vehicle on the road.
     *
     * BEHIND `vehicle.assign`, NOT `vehicle.edit`. Whose licence is on the line when a truck is
     * stopped is not the same kind of decision as correcting its tyre size, and a transport clerk
     * who may do the second should not, by the same right, be able to do the first.
     */
    public function assignDriver(Vehicle $vehicle, ?int $driverId, User $actor): Vehicle
    {
        if ((int) $vehicle->assigned_driver_id === (int) $driverId) {
            return $vehicle;
        }

        return DB::transaction(function () use ($vehicle, $driverId, $actor) {
            $previous = $vehicle->assigned_driver_id;

            $vehicle->forceFill(['assigned_driver_id' => $driverId])->save();
            $vehicle->load('assignedDriver');

            $this->activity->record(
                $vehicle,
                $driverId === null ? 'driver_cleared' : 'driver_assigned',
                $driverId === null
                    ? $actor->name.' removed the assigned driver from '.$vehicle->registration_number.'.'
                    : $actor->name.' made '.($vehicle->assignedDriver?->name ?? 'a driver')
                        .' the driver of '.$vehicle->registration_number.'.',
                array_filter(['previous_driver_id' => $previous]),
                $actor,
            );

            return $vehicle;
        });
    }

    // ================================================================== the odometer

    /**
     * Move the counter forward. THE ONLY THING THAT WRITES `current_odometer`.
     *
     * Called from three places — a fuel fill, a completed service, and a reading typed on the
     * vehicle page — and all three go through the same guard, which is the point of having one.
     *
     * A reading EQUAL to the current one is accepted silently rather than refused: two fills at the
     * same reading is a plausible day (topped up, went nowhere, topped up again) and refusing it
     * would block a legitimate fuel record over nothing.
     */
    public function recordOdometer(Vehicle $vehicle, ?int $reading): Vehicle
    {
        if ($reading === null) {
            return $vehicle;
        }

        return DB::transaction(function () use ($vehicle, $reading) {
            Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $vehicle->refresh();

            return $this->recordLockedOdometer($vehicle, $reading);
        });
    }

    private function recordLockedOdometer(Vehicle $vehicle, int $reading): Vehicle
    {

        $current = $vehicle->current_odometer;

        if ($current !== null && $reading < (int) $current) {
            throw new \DomainException(
                'The odometer on '.$vehicle->registration_number.' already reads '
                .number_format((float) $current).' '.$vehicle->odometer_unit
                .', so a reading of '.number_format((float) $reading).' cannot be right. '
                .'Check the figure — an odometer never goes backwards.'
            );
        }

        if ($current !== null && $reading === (int) $current) {
            return $vehicle;
        }

        $vehicle->forceFill(['current_odometer' => $reading])->save();

        return $vehicle;
    }

    // ================================================================== fuel

    /**
     * File a fill. A receipt, not a stock movement — see the class docblock.
     *
     * THE TOTAL IS THE PRIMARY FACT AND THE UNIT PRICE IS DERIVED, which is the opposite of how a
     * purchase order works and is correct here for a mundane reason: the driver is holding a receipt
     * that says litres and total, and the pump price is what falls out of the two. Deriving the
     * total from a typed unit price instead would produce a figure that disagrees with the paper by
     * a few pence on every fill and by a real number over a year.
     *
     * THE ODOMETER IS RECORDED THROUGH THE GUARD, so a driver who transposes a reading is told at
     * the point of typing rather than producing a consumption figure nobody can explain.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function logFuel(Vehicle $vehicle, array $attributes, User $actor): FuelLog
    {
        $litres = round((float) ($attributes['litres'] ?? 0), 3);

        if ($litres <= 0) {
            throw new \DomainException('A fuel record needs the number of litres that went into the tank.');
        }

        $odometer = blank($attributes['odometer'] ?? null) ? null : (int) $attributes['odometer'];

        // Configurable, because the two halves of the market genuinely differ: a haulier lives or
        // dies by consumption per 100km and wants the reading compulsory, while a small firm with
        // two vans wants the fuel spend and nothing else. Checked here as well as in the form
        // request so a seeder and an import obey the company's rule too.
        if ($odometer === null && $this->settings->bool('fuel_log.odometer_required', true, $vehicle->organization_id)) {
            throw new \DomainException(
                'This company records the odometer on every fill. Enter the reading, or turn the '
                .'requirement off in Settings → Assets & fleet.'
            );
        }

        return DB::transaction(function () use ($vehicle, $attributes, $actor, $litres, $odometer) {
            // Before the log is written, so a backwards reading refuses the whole thing rather than
            // leaving a fuel record attached to a rejected odometer.
            $this->recordOdometer($vehicle, $odometer);

            $total = blank($attributes['total_cost'] ?? null) ? null : round((float) $attributes['total_cost'], 2);

            $log = new FuelLog([
                'station' => $attributes['station'] ?? null,
                'receipt_reference' => $attributes['receipt_reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $log->organization_id = $vehicle->organization_id;
            $log->vehicle_id = $vehicle->getKey();
            $log->filled_at = $attributes['filled_at'] ?? now();
            $log->litres = $litres;
            $log->odometer = $odometer;
            $log->total_cost = $total;
            $log->unit_cost = $total === null ? null : round($total / $litres, 4);
            $log->is_full_tank = (bool) ($attributes['is_full_tank'] ?? true);
            $log->driver_id = $attributes['driver_id'] ?? $vehicle->assigned_driver_id ?? $actor->getKey();
            $log->project_id = blank($attributes['project_id'] ?? null) ? null : (int) $attributes['project_id'];
            $log->created_by = $actor->getKey();
            $log->save();

            $this->activity->record(
                $vehicle,
                'fuel_logged',
                $actor->name.' recorded '.$litres.' litres into '.$vehicle->registration_number.'.',
                array_filter([
                    'litres' => (string) $litres,
                    'odometer' => $odometer,
                ]),
                $actor,
                $log->project_id,
            );

            return $log;
        });
    }

    // ================================================================== helpers

    /**
     * Plates are upper-cased and stripped of spaces and dashes.
     *
     * "ABC 123 XY", "abc-123-xy" and "ABC123XY" are one vehicle, and a fleet that stores all three
     * has three vehicles as far as the unique index is concerned. The stored form is the compact
     * one because that is what a search box gets typed into at half past six in the morning.
     */
    private function normalisePlate(mixed $plate): string
    {
        return mb_strtoupper(preg_replace('/[\s\-]+/', '', trim((string) $plate)) ?? '');
    }

    /**
     * Always one of the company's own words (§84).
     *
     * @param  array<int,string>  $fallback
     */
    private function option(string $key, array $fallback, mixed $value, User $actor): ?string
    {
        $allowed = $this->options->keys($key, $fallback, $actor->organization_id);
        $slug = blank($value) ? null : OptionList::slug((string) $value);

        return $slug !== null && in_array($slug, $allowed, true) ? $slug : null;
    }
}
