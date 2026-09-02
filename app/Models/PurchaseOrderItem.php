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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the commitment.
 *
 * NO `quantity_received` COLUMN. It is the sum of the accepted quantities on the receipt lines that
 * point here, computed on demand. The tempting alternative is a counter incremented by
 * GoodsReceiptService — and it is wrong the first time a receipt is corrected, deleted or entered
 * twice, which is precisely when somebody is looking at it.
 *
 * `unit_price` IS COPIED FROM THE QUOTATION, not read through it. The supplier's price list moves;
 * what we agreed to pay does not. `quotation_item_id` records where the figure came from so the
 * two can be compared, and they are allowed to differ — negotiation after a quotation is normal,
 * and an order that could not hold a negotiated price would be an order nobody could raise.
 */
class PurchaseOrderItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:2',
            'required_by' => 'date',
            'line_no' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    /**
     * Every CONFIRMED receipt line raised against this order line.
     *
     * DRAFTS ARE EXCLUDED, and that is the whole point of the constraint. A receipt being typed is
     * not a delivery, and counting one would let a half-entered goods received note flip its order
     * to "received in full" the next time anything recalculated — a state nobody chose, off a
     * document nobody has confirmed.
     *
     * The test is `received_at`, not the status, deliberately: it is one column on one row, it is
     * set by GoodsReceiptService::confirm() and by nothing else, and it does not depend on a status
     * vocabulary a company is free to rename. A disputed receipt still counts, because the goods it
     * accepted did arrive — the dispute is about the part it rejected.
     *
     * @return HasMany<GoodsReceiptItem,$this>
     */
    public function receiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)
            ->whereHas('goodsReceipt', fn ($query) => $query->whereNotNull('received_at'));
    }

    // ------------------------------------------------------------------ arithmetic

    /** What has actually been taken into stock against this line. Requires `receiptItems` loaded. */
    public function acceptedQuantity(): float
    {
        return (float) $this->receiptItems->sum(fn (GoodsReceiptItem $item) => (float) $item->quantity_accepted);
    }

    /** What arrived and was refused. Not the same number, and not subtracted from the outstanding. */
    public function rejectedQuantity(): float
    {
        return (float) $this->receiptItems->sum(fn (GoodsReceiptItem $item) => (float) $item->quantity_rejected);
    }

    /**
     * Still owed by the supplier.
     *
     * Floored at zero: suppliers over-deliver, and a line reporting minus two outstanding reads as
     * a defect to everybody who sees it. The over-delivery is still visible in `acceptedQuantity()`
     * exceeding `quantity`, which is where it belongs.
     */
    public function outstandingQuantity(): float
    {
        return max(0.0, (float) $this->quantity - $this->acceptedQuantity());
    }

    public function isFullyReceived(): bool
    {
        return $this->outstandingQuantity() <= 0.0;
    }

    public function computedTotal(): string
    {
        return number_format((float) $this->quantity * (float) $this->unit_price, 2, '.', '');
    }

    public function label(): string
    {
        return trim(Quantity::withUnit($this->quantity, $this->uom).' — '.(string) $this->description);
    }
}
