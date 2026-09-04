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

/**
 * How much of one item is in one store, right now.
 *
 * THIS ROW IS A CACHE AND HAS NO AUTHORITY. Every figure on it can be recomputed by replaying
 * `stock_transactions`, and where the two disagree the ledger is right by definition — there is a
 * rebuild command that says so in code. It exists because a store with four years of movements
 * cannot re-sum its history to draw a page, and a foreman asking whether there are gaskets is
 * asking a question that has to be answered in milliseconds.
 *
 * IT IS WRITTEN UNDER A ROW LOCK, INSIDE THE LEDGER'S OWN TRANSACTION. That is the whole of the
 * concurrency design: two storekeepers issuing the last four gaskets at the same moment produce one
 * success and one refusal, because the second waits on the first's `lockForUpdate()` and then reads
 * a balance of zero. Without the lock both read four, both succeed, and the store owes somebody
 * four gaskets it never had.
 *
 * `quantity_reserved` IS THE ONE FIGURE NOT DERIVED FROM THE LEDGER, and it is not an inconsistency:
 * an approved material request holds stock that is still physically on the shelf. A promise leaves
 * no movement to sum, so it is maintained by MaterialRequestService alongside the approval, and the
 * rebuild recomputes it from the open approved requests rather than from movements.
 *
 * `available()` IS WHAT ANYBODY ACTUALLY MEANS. On hand minus reserved. A store that shows forty
 * available when thirty-eight are promised to somebody else will disappoint two people.
 */
class StockBalance extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'quantity_reserved' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'total_value' => 'decimal:2',
            'reorder_level' => 'decimal:4',
            'last_movement_at' => 'datetime',
            'last_counted_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_counted_by');
    }

    // ------------------------------------------------------------------ state

    /** On hand less what is already promised. Never negative in the reading, only in the truth. */
    public function available(): float
    {
        return (float) $this->quantity_on_hand - (float) $this->quantity_reserved;
    }

    /**
     * The level in force here: the store's override, or the catalogue's.
     *
     * Reads the loaded `stockItem` relation and returns null when it is absent rather than
     * lazy-loading it — an index page drawing two hundred balances must not fire two hundred
     * queries to decide which ones to colour red.
     */
    public function effectiveReorderLevel(): ?float
    {
        if ($this->reorder_level !== null) {
            return (float) $this->reorder_level;
        }

        $itemLevel = $this->relationLoaded('stockItem') ? $this->stockItem?->reorder_level : null;

        return $itemLevel === null ? null : (float) $itemLevel;
    }

    public function isBelowReorderLevel(): bool
    {
        $level = $this->effectiveReorderLevel();

        return $level !== null && $this->available() <= $level;
    }

    /** @param  Builder<static>  $query */
    public function scopeInWarehouse(Builder $query, ?int $warehouseId): Builder
    {
        return $warehouseId === null ? $query : $query->where('warehouse_id', $warehouseId);
    }

    /** @param  Builder<static>  $query */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where('quantity_on_hand', '!=', 0);
    }
}
