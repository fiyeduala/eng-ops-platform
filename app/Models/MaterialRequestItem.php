<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One line of a request on the store.
 *
 * `stock_item_id` IS REQUIRED HERE AND OPTIONAL ON A REQUISITION LINE, which is the difference
 * between the two registers stated in one column. You cannot ask a store for a description: the
 * store holds items, and the request is a claim about what is on a shelf. `description` rides
 * alongside as the words the requester used, because "the blue ones" is what will be said at the
 * counter and it belongs on the paperwork.
 *
 * `quantityIssued()` IS A QUERY, NOT A COLUMN. It sums the ledger rows whose source is this line,
 * which means a reversal posted after the fact is reflected immediately and a stored figure can
 * never drift away from the movements it claims to summarise.
 */
class MaterialRequestItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:4',
            'quantity_approved' => 'decimal:4',
            'required_by' => 'date',
        ];
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * The movements this line caused.
     *
     * @return MorphMany<StockTransaction,$this>
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockTransaction::class, 'source');
    }

    /**
     * How much has actually gone out, from the ledger.
     *
     * Issues are negative, so the sum is negated to read as a quantity. Reversals net themselves
     * off automatically, which is the entire benefit of not storing this.
     */
    public function quantityIssued(): float
    {
        $sum = $this->relationLoaded('movements')
            ? $this->movements->sum(fn (StockTransaction $movement) => (float) $movement->quantity)
            : (float) $this->movements()->sum('quantity');

        return -$sum;
    }

    /** What was approved but has not gone out yet — what the store still owes this line. */
    public function quantityOutstanding(): float
    {
        return max(0.0, (float) ($this->quantity_approved ?? 0) - $this->quantityIssued());
    }

    public function isFullyIssued(): bool
    {
        return $this->quantity_approved !== null && $this->quantityOutstanding() <= 0.0;
    }
}
