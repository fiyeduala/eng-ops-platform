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
 * What arrived against one line of one order.
 *
 * TWO QUANTITIES, NOT ONE SIGNED FIGURE. `quantity_accepted` is what we took; `quantity_rejected`
 * is what arrived and was refused. A single column with a rejection flag cannot express the
 * ordinary case — forty delivered, thirty-eight good, two damaged — which is most deliveries of
 * anything fragile.
 *
 * ONLY `quantity_accepted` REACHES STOCK. Rejected goods are physically present and commercially
 * ours to return; they are recorded here so the dispute has evidence, and they never become a stock
 * transaction. Phase 5 part 3 wires the ledger to this column and no other.
 *
 * `expires_on` IS HERE AND NOT ON THE STOCK ITEM. Shelf life belongs to the batch that arrived, not
 * to the kind of thing it is: two drums of the same resin from the same supplier expire on
 * different days, and holding the date on the item would give both the later one.
 */
class GoodsReceiptItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_accepted' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'expires_on' => 'date',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * The stock movements this line produced. Normally one; none when the delivery went straight
     * to site, or when nobody ever catalogued what was bought.
     *
     * IT IS ALSO THE IDEMPOTENCY CHECK. GoodsReceiptService::postToStock() asks this before posting
     * so that a receipt disputed, resolved and accepted a second time cannot put the same forty
     * metres of cable onto the shelf twice. The ledger has no unique key that could catch it —
     * receiving the same thing twice on two different days is perfectly legitimate — so the guard
     * has to be the link back to the line that caused it.
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockTransaction::class, 'source');
    }

    /**
     * What this line will become in the store, if anything.
     *
     * THE WHOLE §90 CHAIN IN ONE EXPRESSION, read backwards: this receipt line came off an order
     * line, which came off a requisition line, on which somebody chose a catalogue item. Any link
     * in that may be missing — a direct order raised with no requisition, a requisition line typed
     * as free text — and each missing link means the same thing, which is that the store has
     * nothing to file the goods under. Null is a legitimate answer and the caller skips the line.
     */
    public function stockItem(): ?StockItem
    {
        return $this->purchaseOrderItem?->requestItem?->stockItem;
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /** Everything that came off the lorry for this line, whatever we decided about it. */
    public function quantityDelivered(): float
    {
        return (float) $this->quantity_accepted + (float) $this->quantity_rejected;
    }

    public function hasRejection(): bool
    {
        return (float) $this->quantity_rejected > 0.0;
    }
}
