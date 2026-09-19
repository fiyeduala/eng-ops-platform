<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The commitment. The one record in this chain that binds the company to spend money.
 *
 * FOUR NULLABLE LINKS BACKWARD, AND THAT IS THE POINT. An order may come from a quotation, from a
 * request, from a contract, or from none of them — a burst pipe at two in the morning is a direct
 * order, and a system that refuses it is a system people work around with a phone call and a
 * back-dated record. Nullable is not the same as optional: `procurement.rfq_required_above` decides
 * when competition is expected, and PurchaseOrderService warns and records when an order above the
 * threshold arrives without it. The policy is configuration; the schema stays permissive (§73).
 *
 * `approved_at` AND `issued_at` ARE TWO DIFFERENT EVENTS. Approval is internal authority — somebody
 * with a limit said yes. Issue is the moment the supplier has it in their hands and the company is
 * committed. Days pass between them, and collapsing them loses the answer to "we approved it, why
 * has nothing arrived".
 *
 * THERE IS NO `quantity_received` COLUMN, here or on the lines. What has arrived is the sum of the
 * receipt lines, always, computed. A cached copy is a number that disagrees with the receipts the
 * first time one is corrected, and the correction is exactly the moment somebody is looking.
 *
 * `part_received` AND `received` ARE SET BY GoodsReceiptService, never clicked. They are arithmetic
 * over the receipts, and a person who can set them by hand is a person who can mark an order
 * complete that never arrived.
 */
class PurchaseOrder extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $table = 'purchase_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'ordered_on' => 'date',
            'required_by' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<PurchaseOrderItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('line_no');
    }

    /** @return HasMany<GoodsReceipt,$this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The approver and the buyer who issued it both reach their own orders.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['approved_by', 'issued_by'];
    }

    /**
     * `vendor_id` is the branch companies write first: "orders to agency labour suppliers go to
     * HR", "anything to a foreign supplier goes to finance". `currency` is the second.
     *
     * `total` IS ABSENT, as on the purchase request and for the same reason (§61): value-based
     * routing is done by the workflow engine's approval threshold, which compares the figure
     * without disclosing it to somebody who may route the order but not read it.
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['vendor_id', 'currency', 'contract_id'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Purchase orders';
    }

    // ------------------------------------------------------------------ state

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    /**
     * Whether goods may still be received against this order.
     *
     * An order must be ISSUED before anything can be received against it — receiving against a
     * draft is how goods arrive on a commitment nobody approved — and a cancelled or closed order
     * takes no more deliveries.
     */
    public function acceptsReceipts(): bool
    {
        return $this->isIssued()
            && ! $this->isClosed()
            && ! in_array($this->statusKey(), ['cancelled'], true);
    }

    /**
     * Raised without competition above the threshold the company set.
     *
     * Returns false when no threshold is configured, which is the honest answer for a company that
     * has not asked for competition: not "compliant", but "there is no rule to breach".
     *
     * The comparison is on the BASE-CURRENCY total, because a threshold in naira and an order in
     * dollars otherwise compare two different numbers and the rule silently stops applying to
     * exactly the largest orders.
     */
    public function needsCompetitionJustification(?string $threshold): bool
    {
        if (blank($threshold) || $this->quotation_id !== null || $this->contract_id !== null) {
            return false;
        }

        return (float) $this->totalInBaseCurrency() > (float) $threshold;
    }

    /** The total in the organization's own currency, at the rate fixed when the order was raised. */
    public function totalInBaseCurrency(): string
    {
        $rate = (float) ($this->exchange_rate ?? 0);

        if ($rate <= 0.0) {
            return (string) $this->total;
        }

        return number_format((float) $this->total * $rate, 2, '.', '');
    }

    /**
     * How much of this order has arrived, as accepted quantity over ordered quantity.
     *
     * COMPUTED FROM THE LINES, every time. Expects `items` and their `receiptItems` to be loaded —
     * the show page loads them, and no index page calls this, because doing so would be a query
     * per row.
     *
     * @return array{ordered:float,accepted:float,outstanding:float}
     */
    public function receiptProgress(): array
    {
        $ordered = 0.0;
        $accepted = 0.0;

        foreach ($this->items as $item) {
            $ordered += (float) $item->quantity;
            $accepted += $item->acceptedQuantity();
        }

        return [
            'ordered' => $ordered,
            'accepted' => $accepted,
            // Never negative. Over-delivery happens, and reporting minus three outstanding reads
            // as a bug to everybody who sees it.
            'outstanding' => max(0.0, $ordered - $accepted),
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeForVendor(Builder $query, int|string|null $vendorId): Builder
    {
        return blank($vendorId) ? $query : $query->where('vendor_id', $vendorId);
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingDelivery(Builder $query): Builder
    {
        return $query->statusKey(['issued', 'part_received']);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title'];
    }
}
