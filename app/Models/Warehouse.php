<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A boundary within which stock is counted.
 *
 * NOT A LOCATION, THOUGH IT USUALLY SITS IN ONE. `locations` answers "where on the map"; a
 * warehouse answers "whose count is this". One yard commonly holds three: the main store, the
 * consumables cage a foreman can draw from without paperwork, and the quarantine bay where rejected
 * deliveries wait for the supplier's lorry. Collapsing those into the location would put the
 * rejected valves and the good ones in the same balance, which is the single most expensive
 * mistake an inventory system can make.
 *
 * `acceptsMovements()` IS THE ONE METHOD THAT MATTERS. Every service that writes to the ledger asks
 * it first, and it is why the status graph exists instead of a boolean: a store frozen mid-stocktake
 * is open, staffed and refusing movements, and no flag called `is_active` can express that without
 * lying about one of the three.
 */
class Warehouse extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    /**
     * `warehouse.types`.
     *
     * `transit` is the one that earns its place and the one a developer would leave out: stock on a
     * lorry between two sites belongs somewhere, and the alternative — leaving it in the sending
     * store until it arrives — means a store that shows goods it demonstrably does not have.
     */
    public const DEFAULT_TYPES = ['Store', 'Site store', 'Transit', 'Quarantine'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'allow_negative_stock' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** The storekeeper. Nullable — a transit store is a lorry and has no keeper. */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'keeper_id');
    }

    /** @return HasMany<StockBalance,$this> */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<StockTransaction,$this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    /** @return HasMany<MaterialRequest,$this> */
    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The keeper reaches their own store.
     *
     * This is what `assigned` scope means for a warehouse, and it is how a storekeeper with a
     * department-wide grant still sees only the store they run.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['keeper_id'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['type', 'code'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Warehouses';
    }

    // ------------------------------------------------------------------ state

    /**
     * Whether the ledger may be written against this store right now.
     *
     * Asked by every service before a movement, and deliberately NOT expressed as a scope: a
     * suspended warehouse must still be visible, searchable and reportable — it is movements that
     * stop, not the record.
     */
    public function acceptsMovements(): bool
    {
        return $this->statusKey() === 'active';
    }

    /**
     * Why a movement was refused, in words a storekeeper can act on.
     *
     * Returns null when nothing is wrong, so callers read as `if ($reason = $warehouse->...)`.
     * Mirrors Vendor::unusableReason(), for the same reason: a service that throws
     * "not permitted" tells the person nothing about which of four conditions they tripped.
     */
    public function movementRefusalReason(): ?string
    {
        return match ($this->statusKey()) {
            'active' => null,
            'suspended' => 'Stock is frozen while the stocktake is completed.',
            'closed' => 'The store is closed.',
            default => 'The store is not yet in use.',
        };
    }

    /** @param  Builder<static>  $query */
    public function scopeAcceptingMovements(Builder $query): Builder
    {
        return $query->statusKey('active');
    }

    /** @param  Builder<static>  $query */
    public function scopeType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where('type', $type);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['code', 'name'];
    }
}
