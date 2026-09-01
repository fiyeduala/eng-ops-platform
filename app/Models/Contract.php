<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The agreement, in either direction.
 *
 * ONE TABLE FOR BOTH ENDS. A contract we hold with a client and a contract we place with a
 * subcontractor ask identical questions — what is it worth, when does it expire, how much retention
 * is held, what is committed against it — and the only thing that differs is the sign of the money.
 * That difference lives in `direction`, not in a second table, because every report worth writing
 * ("what expires in the next ninety days") wants both sides in one list.
 *
 * RETENTION IS STORED, NOT COMPUTED. On a construction contract the money certified is not the
 * money paid: a percentage is held back until practical completion and half of that until the
 * defects period ends. `retention_percent` is the rate agreed and `retention_held` is what has
 * actually accumulated — two columns, because the rate is renegotiated mid-contract more often than
 * anybody expects, and deriving the balance from the current rate silently rewrites what was held
 * last year.
 *
 * VARIATIONS ARE CHANGE REQUESTS. `change_requests` already models "somebody asked for this, it
 * costs this, here is who agreed", with a real approval route on it. A contract variation is that
 * record read from the commercial end, and giving contracts their own variation table would be
 * exactly the unconnected-CRUD failure §90 rules out.
 *
 * IT DEFAULTS TO CONFIDENTIAL, unlike every other register in the platform. A contract value is the
 * one number in the application that is commercially damaging in the hands of the wrong colleague —
 * the subcontractor whose price it names is frequently an employee's former employer. The default
 * is deliberately the restrictive one, and a company that wants its contracts open can say so per
 * record.
 */
class Contract extends Model
{
    use RegisterRecord;

    protected $table = 'contracts';

    protected $guarded = ['id'];

    /** `contract.types` — how the money is measured, which is the only classification that matters. */
    public const DEFAULT_TYPES = [
        'Lump sum', 'Remeasurable', 'Schedule of rates', 'Cost reimbursable', 'Target cost',
        'Framework agreement', 'Call-off order', 'Time and materials',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'value' => 'decimal:2',
            'retention_percent' => 'decimal:2',
            'retention_held' => 'decimal:2',
            'advance_percent' => 'decimal:2',
            'signed_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** The Client or the Vendor at the other end. */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The signed PDF, held in document control rather than as an attachment on this row. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The commercial owner reaches their own contracts.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['owner_id'];
    }

    /**
     * `direction` and `contract_type` are the two branches anybody configures — "vendor contracts
     * over 50,000 go to the finance director" is the first route a company writes here.
     *
     * `value` is deliberately absent. A workflow condition that can test the number would let
     * somebody who may route a contract but not read one recover its value by bisection (§61).
     * Routing on money is done through the change-request register, where the amount is already
     * visible to the approver by construction.
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['direction', 'contract_type', 'party_type'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Contracts';
    }

    /**
     * The one register in the application that starts confidential.
     *
     * A contract's value is the single most commercially damaging figure a company holds about
     * itself — the subcontractor whose price it names is frequently somebody's former employer.
     * Starting internal and being narrowed afterwards means every contract is readable company-wide
     * for however long it takes somebody to remember, which is the wrong way round for this record.
     *
     * The migration sets the same default on the column, and that alone was not enough: the
     * register service stamps a value onto every new record before saving, so the database default
     * never fired. Both are kept — the column default protects a raw insert, this protects
     * everything that goes through the service.
     */
    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Confidential;
    }

    // ------------------------------------------------------------------ state

    public function isClientSide(): bool
    {
        return $this->direction === 'client';
    }

    public function isVendorSide(): bool
    {
        return $this->direction === 'vendor';
    }

    public function hasExpired(): bool
    {
        return $this->ends_on !== null && $this->ends_on->isBefore(now()->startOfDay());
    }

    public function expiresWithin(int $days): bool
    {
        return $this->ends_on !== null
            && ! $this->hasExpired()
            && $this->ends_on->isBefore(now()->startOfDay()->addDays($days));
    }

    /**
     * When the defects liability period ends, or null when nobody recorded one.
     *
     * Derived rather than stored, because it is `ends_on` plus a number of months and storing it
     * would let the two disagree the first time a completion date moves — which it always does.
     */
    public function defectsLiabilityEndsOn(): ?Carbon
    {
        if ($this->ends_on === null || ! $this->defects_liability_months) {
            return null;
        }

        return $this->ends_on->copy()->addMonths((int) $this->defects_liability_months);
    }

    /** The party as a name, whether it is linked or only typed. */
    public function partyLabel(): string
    {
        return (string) ($this->party?->name ?? $this->party_name ?? 'Not recorded');
    }

    /** @param  Builder<static>  $query */
    public function scopeDirection(Builder $query, ?string $direction): Builder
    {
        return blank($direction) ? $query : $query->where('direction', $direction);
    }

    /** @param  Builder<static>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('ends_on')
            ->whereBetween('ends_on', [now()->startOfDay(), now()->startOfDay()->addDays($days)]);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'reference', 'party_name'];
    }

    /** A contract is "due" at its end date — that is what the expiry watch reads. */
    public function registerDueColumn(): ?string
    {
        return 'ends_on';
    }
}
