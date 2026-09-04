<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of one item in one store. The record of record.
 *
 * APPEND-ONLY, AND ENFORCED IN THE MODEL RATHER THAN TRUSTED TO DISCIPLINE (§59). The boot hooks
 * below throw on any update and any delete, of any row, from any caller — service, controller,
 * tinker session or seeder. There is no `->update()` path and no soft delete to hide behind. A
 * movement entered wrongly is corrected by `reverse()`, which writes the opposite movement pointing
 * back at the original, so the pair reads as what actually happened: somebody posted forty at
 * 14:32 and took it back at 14:36.
 *
 * That is a deliberately blunt instrument. The alternative — a policy that forbids editing —
 * protects the HTTP door and leaves every other one open, and this is the table whose integrity the
 * whole of inventory rests on. If a future requirement genuinely needs a ledger row amended, it
 * needs a reversal, which is the same requirement said correctly.
 *
 * SIGNED QUANTITIES. `+` into the store, `-` out of it, so a balance is a plain SUM. The `type`
 * says which kind of movement it was and can never contradict the sign, because StockService
 * derives one from the other and nothing else writes here.
 *
 * NO `updated_at`. Not an oversight and not laziness: a column that can never change is a column
 * that tells a reader something false about what this table permits.
 */
class StockTransaction extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const UPDATED_AT = null;

    /** Into the store. */
    public const INBOUND = ['receipt', 'transfer_in', 'return'];

    /** Out of it. */
    public const OUTBOUND = ['issue', 'transfer_out'];

    /**
     * `adjustment` and `reversal` are in NEITHER list, and that is the point: an adjustment can go
     * either way — a stocktake finds three more or two fewer — and a reversal takes the sign of
     * whatever it undoes. Deriving their direction from the type would be inventing an answer.
     */
    public const TYPES = ['receipt', 'issue', 'transfer_out', 'transfer_in', 'adjustment', 'return', 'reversal'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
            'balance_after' => 'decimal:4',
            'occurred_at' => 'datetime',
            'expires_on' => 'date',
        ];
    }

    /**
     * The two hooks that make the docblock above true.
     *
     * `updating` rather than `saving`, so creating a row is untouched; `deleting` covers both the
     * hard delete and the forced one. Both throw rather than returning false: a silent no-op leaves
     * the caller believing the edit landed, which is worse than the edit landing.
     */
    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            throw new \LogicException(
                'Stock movements cannot be edited. Post a reversal against '.$transaction->number.' instead.'
            );
        });

        static::deleting(function (self $transaction): void {
            throw new \LogicException(
                'Stock movements cannot be deleted. Post a reversal against '.$transaction->number.' instead.'
            );
        });
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What caused the movement, at LINE granularity: a goods receipt line, a material request line.
     *
     * Pointing at the header instead would make "how much of line 3 has been issued" unanswerable,
     * which is the question the whole chain exists to answer.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** The movement this one undoes, if it is a correction. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    // ------------------------------------------------------------------ state

    public function isInbound(): bool
    {
        return (float) $this->quantity > 0.0;
    }

    /** A movement that has already been corrected — shown struck through, never hidden. */
    public function isReversed(): bool
    {
        return self::query()
            ->where('reverses_transaction_id', $this->getKey())
            ->exists();
    }

    // ------------------------------------------------------------------ presentation

    /**
     * The quantity as a store would say it: "40 m in", "12 ea out".
     *
     * The sign is rendered as a WORD rather than left as a minus, because a column of figures half
     * of which are negative is read wrongly by everybody at least once.
     */
    public function movementLabel(): string
    {
        $quantity = Quantity::withUnit(abs((float) $this->quantity), $this->stockItem?->uom);

        return $quantity.' '.($this->isInbound() ? 'in' : 'out');
    }

    /** @param  Builder<static>  $query */
    public function scopeForItem(Builder $query, int $stockItemId): Builder
    {
        return $query->where('stock_item_id', $stockItemId);
    }

    /** @param  Builder<static>  $query */
    public function scopeInWarehouse(Builder $query, ?int $warehouseId): Builder
    {
        return $warehouseId === null ? $query : $query->where('warehouse_id', $warehouseId);
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'batch_ref', 'reason'];
    }
}
