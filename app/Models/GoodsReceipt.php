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
 * What actually turned up, and in what condition.
 *
 * A RECEIPT IS AN OBSERVATION, AND OBSERVATIONS ARE NOT AMENDED. Somebody stood at a gate, counted
 * what came off a lorry and wrote it down. When the count was wrong the answer is a second receipt
 * — a correction with its own date and its own author — not an edit that rewrites what the first
 * person said they saw. GoodsReceiptService closes editing once the receipt leaves `draft`, and
 * this is why: it is the record the stock ledger and the invoice check both rest on.
 *
 * MANY RECEIPTS PER ORDER. Deliveries arrive in parts, from different lorries, on different days,
 * and each is its own record. An order with a single receipt attached would force a storekeeper to
 * either hold the paperwork until everything arrives or edit yesterday's count.
 *
 * ACCEPTED, REJECTED AND OUTSTANDING ARE THREE DIFFERENT NUMBERS, held on the lines. Rejected goods
 * arrived and were refused: they are not outstanding, because the supplier delivered them, and they
 * are not received, because we will not pay for them. Only accepted quantities reach stock.
 *
 * SEGREGATION OF DUTIES, SECOND HALF. The person who raised the order should not be the person who
 * signs for its delivery. `procurement.separate_buyer_and_receiver` governs, defaulting to on, and
 * GoodsReceiptService enforces it — a four-person facilities company can turn it off, and the
 * override is recorded where it happens.
 */
class GoodsReceipt extends Model
{
    use RegisterRecord;

    protected $table = 'goods_receipts';

    protected $guarded = ['id'];

    /**
     * `goods_receipt.conditions` — what a storekeeper writes about how it looked.
     *
     * SEPARATE FROM ACCEPTANCE, deliberately. Goods arrive damaged and are accepted anyway because
     * the site needs them today; goods arrive perfect and are rejected because they are the wrong
     * item. Condition describes the goods, quantities describe the decision, and merging the two
     * loses one of them.
     */
    public const DEFAULT_CONDITIONS = ['Good', 'Damaged', 'Wrong item', 'Short shipped', 'Expired'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<GoodsReceiptItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The store the delivery went into, when it went into one (Phase 5 part 3).
     *
     * NULLABLE, because a delivery to site that is consumed the same afternoon never enters a
     * store, and inventing a warehouse to satisfy a NOT NULL would put stock in a place that does
     * not exist. When it IS set, accepting this receipt posts the ledger rows — the middle link of
     * the chain the phase exits on (§90).
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The receiver and the inspector both reach their own receipts.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['received_by', 'inspected_by'];
    }

    /**
     * `location_id` is the branch that matters here: "deliveries to the Warri yard are inspected by
     * the yard supervisor" is the first rule anybody with more than one site writes.
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['location_id', 'purchase_order_id'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Goods receipts';
    }

    // ------------------------------------------------------------------ state

    /**
     * Whether the counts may still be changed.
     *
     * Only while it is a draft. See the class docblock: after that a correction is a new receipt,
     * because the stock ledger and the invoice check are both already resting on these figures.
     */
    public function isEditable(): bool
    {
        return $this->statusKey() === 'draft';
    }

    public function hasRejections(): bool
    {
        return $this->items->contains(fn (GoodsReceiptItem $item) => (float) $item->quantity_rejected > 0.0);
    }

    public function isDisputed(): bool
    {
        return $this->statusKey() === 'disputed';
    }

    /**
     * The totals across the whole delivery. Requires `items` loaded.
     *
     * @return array{accepted:float,rejected:float,lines:int}
     */
    public function summary(): array
    {
        return [
            'accepted' => (float) $this->items->sum(fn (GoodsReceiptItem $i) => (float) $i->quantity_accepted),
            'rejected' => (float) $this->items->sum(fn (GoodsReceiptItem $i) => (float) $i->quantity_rejected),
            'lines' => $this->items->count(),
        ];
    }

    /**
     * "Everything delivered to the refinery job", from a record that has no project of its own.
     *
     * A delivery belongs to an order and the order belongs to the project. Filtering through the
     * relation rather than a column this table does not have keeps the shared filter bar honest, and
     * the order's own tenant scope still applies (§75).
     *
     * @param  Builder<static>  $query
     */
    public function scopeForProject(Builder $query, int|string|null $projectId): Builder
    {
        return blank($projectId)
            ? $query
            : $query->whereHas('purchaseOrder', fn (Builder $q) => $q->where('project_id', $projectId));
    }

    /** @param  Builder<static>  $query */
    public function scopeForOrder(Builder $query, int|string|null $orderId): Builder
    {
        return blank($orderId) ? $query : $query->where('purchase_order_id', $orderId);
    }

    // ------------------------------------------------------------------ presentation

    /**
     * A receipt has no title. It is identified by its number and the delivery note that came with
     * it, which is what a storekeeper searches for when the supplier phones.
     */
    public function registerTitleColumn(): string
    {
        return 'delivery_note_ref';
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'delivery_note_ref', 'carrier'];
    }

    /** Nothing about a receipt is due. It either happened or it has not. */
    public function registerDueColumn(): ?string
    {
        return null;
    }
}
