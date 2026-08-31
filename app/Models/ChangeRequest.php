<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\ChangeDisposition;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An engineering change request: the design moved, and here is why, whose fault it was, and what it
 * cost.
 *
 * STATUS AND DISPOSITION ARE TWO DIFFERENT FIELDS, and the separation is the reason this register
 * is worth having. Status is where the request has reached in this company's own process — drafted,
 * submitted, under review, closed — and every part of it is configurable. Disposition is the answer:
 * approved, rejected, deferred, withdrawn. A request can be closed and rejected, or open and
 * deferred, and reporting needs both — "how many changes did we approve" is a different question
 * from "how many are still sitting with the client", and a single column cannot answer either
 * without lying about the other.
 *
 * MONEY CARRIES NO CURRENCY COLUMN. The project carries the currency; duplicating it here would let
 * the two disagree, and the row that disagrees is the one somebody sums. Nothing about tax or
 * jurisdiction is implied or encoded (§47).
 *
 * `cost_impact` IS AN ESTIMATE and is named as one throughout the UI. The authoritative number
 * arrives with the variation order in Phase 5; recording an estimate as though it were settled is
 * how a change register ends up contradicting the accounts.
 */
class ChangeRequest extends Model
{
    use RegisterRecord;

    /**
     * Where the change came from. A configurable-looking list that is deliberately fixed, because
     * "how many of our changes were client-driven" is the question the register is kept to answer,
     * and a tenant-specific origin makes that number incomparable with every other project's.
     */
    public const ORIGINS = [
        'client' => 'Client instruction',
        'internal' => 'Internal — design development',
        'vendor' => 'Vendor or supplier',
        'site' => 'Site condition',
        'regulatory' => 'Regulatory or code change',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'disposition' => ChangeDisposition::class,
            'confidentiality' => Confidentiality::class,
            'cost_impact' => 'decimal:2',
            'schedule_impact_days' => 'integer',
            'raised_on' => 'date',
            'required_by' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['raised_by', 'decided_by'];
    }

    /**
     * Origin and disposition, plus the two impact columns.
     *
     * `cost_impact` is here because "anything over 50,000 goes to the board" is the single most
     * common approval rule any company writes, and a workflow that cannot read the number cannot
     * express it. It is a decimal, not free text — no §61 concern.
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['origin', 'disposition', 'cost_impact', 'schedule_impact_days'];
    }

    // ------------------------------------------------------------------ helpers

    public function isDecided(): bool
    {
        return $this->decided_at !== null;
    }

    public function originLabel(): ?string
    {
        return $this->origin === null ? null : (self::ORIGINS[$this->origin] ?? $this->origin);
    }

    /** Whether this change actually moves the contract — the only disposition that costs anybody. */
    public function changesTheContract(): bool
    {
        return $this->disposition instanceof ChangeDisposition && $this->disposition->changesTheContract();
    }

    /** @param  Builder<static>  $query */
    public function scopeUndecided(Builder $query): Builder
    {
        return $query->whereNull('decided_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('disposition', ChangeDisposition::Approved->value);
    }
}
