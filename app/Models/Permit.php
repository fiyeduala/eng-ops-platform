<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Expiry\Concerns\HasExpiryDates;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permission to do something dangerous, for a stated period.
 *
 * THE ONLY RECORD IN THIS APPLICATION THAT EXPIRES BY THE CLOCK. `valid_to` is not a target date
 * somebody might miss — it is the moment the authorisation stops, and work continuing past it is
 * itself the finding. Everything about this model follows from that: `isLive()` is a question about
 * right now, the register's default view is "live on site", and `hasExpired()` is deliberately
 * separate from the generic `isOverdue()`, which means something much weaker everywhere else.
 *
 * FOUR SIGNATURES, FOUR COLUMNS, FOUR PEOPLE. Requested, issued, accepted, closed — and frequently
 * a fifth suspends or cancels. Each is a different act with a different authority behind it, and the
 * reason this is the most heavily separated record in the phase is the oldest rule in permit-to-work:
 * THE PERSON WHO WANTS THE WORK DONE MUST NEVER BE THE PERSON WHO AUTHORISES IT. PermitPolicy
 * enforces it at any scope, and no configuration relaxes it.
 *
 * ACCEPTING IS NOT ISSUING. Issuing grants the authority; accepting is the performing supervisor
 * taking responsibility for working within it. A permit issued and never accepted is a permit
 * nobody has agreed to follow, which is why both timestamps exist and why `isLive()` requires the
 * second.
 *
 * SUSPENSION IS NOT CLOSURE. A gas alarm suspends a confined-space permit for two hours; the work
 * resumes under the same permit and the same precautions. A register that could only close and
 * reissue would lose the fact that it was the same job, and would tempt people to just carry on.
 */
class Permit extends Model
{
    use HasCustomFields, HasExpiryDates, RegisterRecord;

    /**
     * A permit to work that has quietly passed its end time is the most dangerous stale record in
     * this platform: somebody is standing in a confined space on the authority of a piece of paper
     * that stopped being valid at four o'clock. Watched in the company's words, not the column's.
     *
     * @var array<string,string>
     */
    protected static array $expiryDates = [
        'valid_to' => 'Permit valid until',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'gas_tested_at' => 'datetime',
            'gas_test_required' => 'boolean',
            'worker_count' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    public function precautions(): HasMany
    {
        return $this->hasMany(PermitPrecaution::class)->orderBy('sort_order')->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Whoever held the meter.
     *
     * Its own relation rather than a name folded into the result string, because "who took this
     * reading" is a question asked of the record long after the person has left, and a free-text
     * result that happened to mention a name would not survive the asking.
     */
    public function gasTester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gas_tested_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * Requesting or accepting a permit reaches it.
     *
     * The issuer is deliberately NOT here. Issuing is an authority somebody exercises across the
     * whole area they are responsible for, and it is granted by permission and scope rather than by
     * having touched this particular record — an issuer who could only see permits they had already
     * issued could never see the one waiting for them.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['requested_by', 'accepted_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        // Type is what every routing rule branches on: confined space goes to the authorised gas
        // tester, hot work to the fire warden.
        return ['permit_type_id', 'gas_test_required'];
    }

    // ------------------------------------------------------------------ state

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null && $this->closed_at === null && $this->cancelled_at === null;
    }

    public function isClosedOut(): bool
    {
        return $this->closed_at !== null || $this->cancelled_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->valid_to !== null && $this->valid_to->isPast();
    }

    /**
     * Work may proceed under this permit RIGHT NOW.
     *
     * Every clause earns its place: issued (somebody authorised it), accepted (somebody took it
     * on), not suspended, not closed or cancelled, and inside its window at this moment. Drop any
     * one and the register starts telling a control room that a permit is live when it is not,
     * which is the failure this whole module exists to prevent.
     */
    public function isLive(): bool
    {
        if (! $this->isIssued() || ! $this->isAccepted() || $this->isSuspended() || $this->isClosedOut()) {
            return false;
        }

        $now = now();

        return ($this->valid_from === null || $this->valid_from->lessThanOrEqualTo($now))
            && ($this->valid_to === null || $this->valid_to->greaterThanOrEqualTo($now));
    }

    /** Every required precaution confirmed. The service refuses to issue until this is true. */
    public function requiredPrecautionsConfirmed(): bool
    {
        return $this->precautions()
            ->where('is_required', true)
            ->where('is_confirmed', false)
            ->doesntExist();
    }

    /** @param  Builder<static>  $query */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->whereNotNull('issued_at')
            ->whereNotNull('accepted_at')
            ->whereNull('closed_at')
            ->whereNull('cancelled_at')
            ->whereNull('suspended_at')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now));
    }

    /** Issued, still open, and past its window — the report a safety officer opens first. */
    public function scopeExpiredAndOpen(Builder $query): Builder
    {
        return $query->whereNotNull('issued_at')
            ->whereNull('closed_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('valid_to')
            ->where('valid_to', '<', now());
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'location', 'equipment'];
    }

    public function registerDueColumn(): ?string
    {
        return 'valid_to';
    }
}
