<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The engine half of a vehicle. One row per asset that has one.
 *
 * A VEHICLE IS AN ASSET, and this is the deliberate consequence: everything a pickup shares with a
 * generator — how it was bought, whose it is, what it has cost, when it was serviced, that it was
 * retired — lives on `assets` and is not repeated here. What is here is what only a vehicle has: a
 * plate, an odometer, a tank. Building the fleet as its own register would mean custody history
 * works for generators and not for trucks, which is the same bug written twice.
 *
 * `current_odometer` IS A CACHE, the third in this part built the same way as the other two. The
 * truth is the highest reading in `fuel_logs` (and the maintenance readings beside them); the
 * fleet service writes this column in the same transaction as the log, and it can be rebuilt. It
 * exists so a fleet page can be drawn without a subquery per row.
 *
 * `odometer_unit` IS NOT DECORATION EITHER. Plant is metered in RUNNING HOURS, not distance, and a
 * generator whose service interval is 250 hours would otherwise be reported as needing a service
 * every 250 kilometres. The column is what lets one table serve both without lying about either.
 *
 * NO CONFIDENTIALITY COLUMN, and that is on purpose. What may be seen about a vehicle is decided by
 * its asset — see VehiclePolicy, which defers. A second, independent confidentiality here would be
 * two answers to one question, and the looser one would win by accident.
 */
class Vehicle extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /** `vehicle.types`. Configurable words; a starting point (§73). */
    public const DEFAULT_TYPES = [
        'Pickup', 'Saloon car', 'Bus', 'Tipper truck', 'Tanker', 'Excavator', 'Forklift', 'Generator',
    ];

    /** `vehicle.fuel_types`. */
    public const DEFAULT_FUEL_TYPES = ['Diesel', 'Petrol', 'CNG', 'Electric', 'Hybrid'];

    /** Distance, distance, and time — see the class docblock on why plant needs the third. */
    public const ODOMETER_UNITS = ['km', 'mi', 'hours'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tank_capacity' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    /** @return HasMany<FuelLog,$this> */
    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class);
    }

    /** @return HasMany<VehicleDocument,$this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    // ------------------------------------------------------------------ access hooks

    public function accessParentRelation(): ?string
    {
        return 'asset';
    }

    /**
     * The driver reaches the vehicle they are assigned.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['assigned_driver_id'];
    }

    /**
     * Nobody OWNS a vehicle row — the asset carries that, and this table has no `created_by`.
     *
     * Stated rather than left to the default, because Scopeable's default names `created_by` and
     * `owner_id`, and a reader finding neither on the table should be able to tell it was decided
     * rather than forgotten.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** The department and the job are the asset's answer, and they change. See the docblock. */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ state

    /**
     * The newest odometer reading, from the cache.
     *
     * Null when the vehicle has never been fuelled or serviced through the system, which is a real
     * state and not zero: a truck reading 0 km and a truck nobody has recorded a reading for are
     * different situations, and the second must not compute a fuel consumption.
     */
    public function odometer(): ?int
    {
        return $this->current_odometer === null ? null : (int) $this->current_odometer;
    }

    /**
     * Litres per 100 units of distance, between the two newest FULL fills.
     *
     * NULL IS AN ANSWER HERE, and giving it honestly is the whole point of the method. Consumption
     * needs a closed interval — a full tank, some distance, another full tank — so a vehicle with
     * one log, with a partial fill at either end, or with two logs at the same reading has no
     * consumption to report. The alternative is a figure computed from a partial fill, which is
     * wrong by an unknown amount and looks exactly like a right one.
     *
     * NOTHING IS STORED. Fuel receipts arrive in batches on Fridays and are entered out of order; a
     * stored figure would disagree with the rows it came from the moment a back-dated log landed,
     * and nobody could tell which was wrong.
     *
     * Requires `fuelLogs` to be loaded, and says so by using the collection.
     */
    public function consumptionPer100(): ?float
    {
        // A fill without a reading cannot be placed in a distance interval reliably.
        if ($this->fuelLogs->contains(fn (FuelLog $log) => $log->odometer === null)) {
            return null;
        }

        $full = $this->fuelLogs
            ->filter(fn (FuelLog $log) => (bool) $log->is_full_tank && $log->odometer !== null)
            ->sortByDesc('odometer')
            ->values();

        if ($full->count() < 2) {
            return null;
        }

        $latest = $full[0];
        $previous = $full[1];
        $distance = (int) $latest->odometer - (int) $previous->odometer;

        if ($distance <= 0) {
            return null;
        }

        $litres = $this->fuelLogs
            ->filter(fn (FuelLog $log) => (int) $log->odometer > (int) $previous->odometer
                && (int) $log->odometer <= (int) $latest->odometer)
            ->sum('litres');

        return round(((float) $litres / $distance) * 100, 2);
    }

    /**
     * Documents that have lapsed, from the loaded collection.
     *
     * @return Collection<int,VehicleDocument>
     */
    public function expiredDocuments(): Collection
    {
        return $this->documents->filter(fn (VehicleDocument $doc) => $doc->isCurrent() && $doc->hasExpired())->values();
    }

    /** Whether anything the law requires has lapsed. The one question a gate guard asks. */
    public function hasLapsedDocuments(): bool
    {
        return $this->expiredDocuments()->isNotEmpty();
    }

    /** @param  Builder<static>  $query */
    public function scopeType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where($this->qualifyColumn('vehicle_type'), $type);
    }

    // ------------------------------------------------------------------ presentation

    public function displayName(): string
    {
        return trim($this->registration_number.' — '.($this->asset?->name ?? ''), ' —');
    }

    /**
     * Search the plate and the identifiers, never the asset's free text.
     *
     * The same whitelist argument the register spine makes: a search box that matches description
     * text reports, through the result count alone, whether a phrase appears in records the reader
     * may list but has not opened (§61).
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where($this->qualifyColumn('registration_number'), 'like', $like)
                ->orWhere($this->qualifyColumn('vin'), 'like', $like);
        });
    }
}
