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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One fill. Litres, a reading, and what was paid.
 *
 * CONSUMPTION IS NOT HERE, AND THAT IS THE DESIGN. Litres per 100 km is arithmetic over the
 * PREVIOUS full fill for the same vehicle — see Vehicle::consumptionPer100() — and storing it on
 * this row would mean a back-dated entry leaves a column disagreeing with the rows it was computed
 * from. Fuel receipts arrive in batches on Fridays and are entered out of order constantly; the
 * stored figure would be wrong most weeks and nobody could tell which number to believe.
 *
 * `is_full_tank` IS THE COLUMN THAT MAKES THE ARITHMETIC HONEST. You cannot compute consumption
 * across a partial fill, because you do not know how much was in the tank when it started. A
 * system without this flag computes a number anyway, and that number is wrong by an unknown amount
 * while looking exactly like a right one.
 *
 * `total_cost` IS STORED EVEN THOUGH LITRES × UNIT COST WOULD GIVE IT, which reads like a violation
 * of the rule above and is the opposite. A fuel receipt frequently is NOT the product of the two —
 * a rounded pump total, a discount, a levy, a card fee. What was actually paid is the primary fact
 * here; the unit cost is the derived one, and where the two disagree the receipt is right.
 *
 * SOFT DELETES, UNLIKE THE STOCK LEDGER. A fuel log is a record of expenditure, not a balance
 * anything is computed from: deleting one changes no running total that must reconcile, and a
 * duplicate entered twice at the pump is a genuine mistake with no correcting entry to make. The
 * asymmetry with `stock_transactions` is deliberate rather than an oversight.
 */
class FuelLog extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filled_at' => 'datetime',
            'litres' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'is_full_tank' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessParentRelation(): ?string
    {
        return 'vehicle';
    }

    /**
     * The driver who filled it reaches their own logs.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['driver_id'];
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /**
     * `project_id` is real here and worth scoping on — a project manager should see the fuel
     * charged to their job — but there is no owning department: fuel belongs to the vehicle.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ state

    /**
     * What a litre effectively cost, from what was paid.
     *
     * Derived from `total_cost` rather than read from `unit_cost`, because the receipt is the
     * primary fact — see the class docblock. Null when neither figure was captured.
     */
    public function effectiveUnitCost(): ?float
    {
        $litres = (float) $this->litres;

        if ($this->total_cost !== null && $litres > 0) {
            return round((float) $this->total_cost / $litres, 4);
        }

        return $this->unit_cost === null ? null : (float) $this->unit_cost;
    }

    /**
     * Distance covered since the previous log for this vehicle, or null when there is none.
     *
     * A query rather than a stored column, and it is the honest shape: the "previous" log changes
     * the moment somebody enters a receipt they found in a glovebox. Called on a show page and in
     * the tests, never in an index loop.
     */
    public function distanceSincePrevious(): ?int
    {
        // A fill entered without a reading — which some companies allow, see the migration — has no
        // distance to report, and saying so is better than treating a missing clock as zero.
        if ($this->odometer === null) {
            return null;
        }

        $previous = static::query()
            ->where('vehicle_id', $this->vehicle_id)
            ->whereNotNull('odometer')
            ->where('odometer', '<', $this->odometer)
            ->orderByDesc('odometer')
            ->first();

        return $previous === null ? null : (int) $this->odometer - (int) $previous->odometer;
    }

    /** @param  Builder<static>  $query */
    public function scopeForVehicle(Builder $query, int|string|null $vehicleId): Builder
    {
        return blank($vehicleId) ? $query : $query->where($this->qualifyColumn('vehicle_id'), $vehicleId);
    }

    /** @param  Builder<static>  $query */
    public function scopeFullTank(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_full_tank'), true);
    }

    // ------------------------------------------------------------------ presentation

    public function displayName(): string
    {
        return number_format((float) $this->litres, 2).' L on '.$this->filled_at?->toFormattedDateString();
    }

    /**
     * The receipt reference and the station, and nothing free-text (§61).
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
            $q->where($this->qualifyColumn('receipt_reference'), 'like', $like)
                ->orWhere($this->qualifyColumn('station'), 'like', $like);
        });
    }
}
