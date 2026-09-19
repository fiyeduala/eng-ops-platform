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
 * The enquiry: the same question, asked of several suppliers, on the same day.
 *
 * THE INVITATION LIST IS THE POINT OF THE RECORD. Anybody can email three suppliers; what makes an
 * enquiry auditable is that the system knows who was asked, when, and who did not answer.
 * `rfq_vendors` is therefore a register in its own right rather than a pivot table with a timestamp
 * — a supplier who declined with a reason is evidence, and a supplier silently missing from a pivot
 * is nothing.
 *
 * `closes_at` IS A PROMISE TO THE SUPPLIERS, not a system deadline. Nothing is locked when it
 * passes and no job moves the status: a quotation that arrives an hour late is still a quotation,
 * and a buyer who wants to accept it may. What the date does is put the enquiry in the "closing
 * this week" list and record what everybody was told.
 *
 * THE AWARD LIVES HERE, NOT ON THE QUOTATION. `awarded_quotation_id` is on this row because an
 * award is a decision about the ENQUIRY — one winner, everybody else unsuccessful, in one act. Held
 * as a flag on each quotation instead, two quotations can both be accepted and no query can tell
 * you which. RfqService::award() is the only thing that writes it, and it sets the losing
 * quotations in the same transaction.
 *
 * NO FOREIGN KEY ON `awarded_quotation_id`. `quotations.rfq_id` already points the other way, so a
 * constraint here is circular; see the migration for why the column is indexed instead.
 */
class Rfq extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $table = 'rfqs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'closes_at' => 'datetime',
            'issued_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<RfqItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class)->orderBy('line_no');
    }

    /**
     * The invitations, with their replies.
     *
     * @return HasMany<RfqVendor,$this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(RfqVendor::class);
    }

    /** @return HasMany<Quotation,$this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /**
     * The winner.
     *
     * A plain belongsTo on an unconstrained column: the id is written by RfqService and can only
     * ever name a quotation on this enquiry, which the service enforces because the database
     * cannot.
     */
    public function awardedQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'awarded_quotation_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['issued_by'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Requests for quotation';
    }

    // ------------------------------------------------------------------ state

    /** Past the date everybody was given, whatever the status says. */
    public function hasClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    public function isAwarded(): bool
    {
        return $this->awarded_quotation_id !== null;
    }

    /**
     * Whether a quotation may still be recorded against this enquiry.
     *
     * A closed enquiry still accepts one — see the class docblock — but an awarded or cancelled one
     * does not: the decision is made, and a quotation entered afterwards would sit in the
     * comparison forever having lost a race it was never in.
     */
    public function acceptsQuotations(): bool
    {
        return ! $this->isAwarded() && ! $this->isClosed();
    }

    /**
     * How many suppliers were asked, and how many answered.
     *
     * @return array{invited:int,responded:int,declined:int}
     */
    public function responseSummary(): array
    {
        return [
            'invited' => $this->invitations->count(),
            'responded' => $this->invitations->whereNotNull('responded_at')->count(),
            'declined' => $this->invitations->whereNotNull('declined_at')->count(),
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeClosingWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('closes_at')
            ->whereBetween('closes_at', [now(), now()->addDays($days)]);
    }

    // ------------------------------------------------------------------ presentation

    /** An enquiry is "due" when it closes. */
    public function registerDueColumn(): ?string
    {
        return 'closes_at';
    }
}
