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
 * "We need this." The first link in the chain, and the only one written by somebody who does not
 * work in procurement.
 *
 * A PURCHASE REQUEST IS NOT A SMALL PURCHASE ORDER. The site engineer who raises one knows what is
 * needed and roughly what it costs; they do not know which supplier, at what price, on what terms.
 * So this record carries an ESTIMATE and no vendor at all, and every attempt to put a vendor on it
 * — which somebody will ask for, because "we always buy those from Sahara" — is the request quietly
 * becoming an order raised by a person with no authority to place one.
 *
 * `estimated_total` IS A SUM OF LINES, NOT A TYPED FIGURE. PurchaseRequestService recalculates it
 * from the items on every save, because a header total somebody typed is a number that disagrees
 * with the lines the first time a line is edited — and it is the number the approval threshold
 * reads.
 *
 * WHAT "ORDERED" MEANS HERE. The `ordered` status is set by PurchaseOrderService when an order is
 * raised against this request, never by a person. It is a fact about a different record, and the
 * moment somebody can set it by hand the register grows requests that claim to have been ordered
 * with nothing to show for it.
 */
class PurchaseRequest extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $table = 'purchase_requests';

    protected $guarded = ['id'];

    /**
     * `purchase_request.priorities`.
     *
     * FOUR WORDS, NOT A NUMBER. A 1-5 urgency scale reads as precision it does not have, and every
     * company that has one ends up with everything at 1. These are the words people actually use,
     * and a company that wants different ones changes them in Settings.
     */
    public const DEFAULT_PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'estimated_total' => 'decimal:2',
            'required_by' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<PurchaseRequestItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('line_no');
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

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Enquiries raised to price this request.
     *
     * @return HasMany<Rfq,$this>
     */
    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }

    /**
     * Orders raised against this request — plural, because a request for eleven items is regularly
     * split across three suppliers and a single `purchase_order_id` on this row would record one of
     * them and lose the other two.
     *
     * @return HasMany<PurchaseOrder,$this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The approver reaches the requests waiting on them.
     *
     * `submitted_by` is deliberately absent: it is the same person as `created_by` in all but the
     * rare case where somebody submits on a colleague's behalf, and adding it would widen nothing
     * while making the two columns look interchangeable, which they are not.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['approved_by'];
    }

    /**
     * `priority`, `project_id` and the owning unit are what a company routes on: "urgent requests
     * go straight to the operations director", "anything on the Delta job needs the project
     * manager".
     *
     * `estimated_total` IS ABSENT, for the reason Contract omits `value`: a condition that can test
     * the figure lets somebody who may route a request but not read one recover its value by
     * bisection (§61). Value-based routing is done by the approval THRESHOLD in the workflow
     * engine, which compares without disclosing.
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['priority', 'currency'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Purchase requests';
    }

    // ------------------------------------------------------------------ state

    /**
     * Whether an order may still be raised against this.
     *
     * Reads the status KEY rather than `is_terminal`, because `ordered` is not terminal — a request
     * part-ordered from two suppliers must remain orderable — and `approved` and `ordered` are the
     * two states that permit it.
     */
    public function isOrderable(): bool
    {
        return in_array($this->statusKey(), ['approved', 'ordered'], true);
    }

    public function isAwaitingDecision(): bool
    {
        return $this->statusKey() === 'submitted';
    }

    /**
     * The sum of the lines, from the lines.
     *
     * Used by the service to stamp `estimated_total`, and by the show page when items are already
     * loaded. Never called to DISPLAY a total on an index — that would be a query per row.
     */
    public function computedTotal(): string
    {
        return (string) $this->items->sum(fn (PurchaseRequestItem $item) => (float) $item->estimated_total);
    }

    /** @param  Builder<static>  $query */
    public function scopePriority(Builder $query, ?string $priority): Builder
    {
        return blank($priority) ? $query : $query->where('priority', $priority);
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->statusKey('submitted');
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title'];
    }
}
