<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody needs.
 *
 * `uom` IS FREE TEXT AND THAT IS DELIBERATE. A unit-of-measure enum is the classic false enum: it
 * starts as each/m/kg/litre, and within a month somebody needs "drum (200L)", "lot", "man-day",
 * "linear metre of 6in", "set of 4". Every attempt to close the list ends with people writing the
 * real unit into the description, where nothing can count it. Phase 5 part 3 introduces stock
 * items, which DO carry a canonical unit — and when a line is linked to one, the service copies
 * that unit here rather than opening a second vocabulary.
 *
 * `estimated_unit_price` IS OPTIONAL AND `estimated_total` IS NOT DERIVED FROM IT. An engineer who
 * knows the line costs about two million and does not know the unit rate must be able to say so;
 * demanding a rate they do not have produces an invented one. The service computes the total from
 * quantity times rate ONLY when both are given, and otherwise keeps what was typed.
 *
 * Reach is inherited from the parent request.
 */
class PurchaseRequestItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'estimated_unit_price' => 'decimal:4',
            'estimated_total' => 'decimal:2',
            'required_by' => 'date',
            'line_no' => 'integer',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /**
     * Quantity times rate, when both are known.
     *
     * Returns null rather than zero when the rate is missing, so the caller can tell "this line
     * costs nothing" from "nobody priced this line" — a distinction a zero destroys.
     */
    public function computedTotal(): ?string
    {
        if ($this->quantity === null || $this->estimated_unit_price === null) {
            return null;
        }

        return number_format((float) $this->quantity * (float) $this->estimated_unit_price, 2, '.', '');
    }

    public function label(): string
    {
        return trim(Quantity::withUnit($this->quantity, $this->uom).' — '.(string) $this->description);
    }

    /**
     * The catalogue entry this line is asking for, when the requester knew one.
     *
     * NULLABLE ON PURPOSE (Phase 5 part 3). A site engineer types what they need; insisting on a
     * catalogue code before anybody may raise a requisition is how requisition systems get filled
     * in wrongly. When it IS set, the buyer can see the yard already holds forty of it.
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
