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
 * What could go wrong doing this, and what we have done about it.
 *
 * A RISK ASSESSMENT EXPIRES, which makes it unlike every other record in this application. `valid_to`
 * is not a target date somebody might miss — it is the moment the assessment stops being the basis
 * for the work, and an assessment that lapsed four months ago is worse than no assessment at all,
 * because the work carried on under it. That is why the register's default view is "expiring soon"
 * rather than "everything".
 *
 * ASSESSED, REVIEWED AND APPROVED ARE THREE COLUMNS AND THREE PEOPLE. Of all the separation-of-duty
 * rules in this phase, this is the one that is a legal expectation rather than a house preference:
 * the person who wrote the assessment cannot be the only signature on it. Collapsing them into a
 * single `signed_by` would make the document unusable as the evidence it exists to be.
 *
 * THE RESIDUAL SCORE IS THE ANSWER. Raw risk says what is dangerous; residual risk says whether the
 * controls are enough, and only the second is the question being asked. See RiskAssessmentHazard.
 */
class RiskAssessment extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    /** `risk_assessment.types`. */
    public const DEFAULT_TYPES = [
        'Task based', 'COSHH', 'Manual handling', 'Environmental aspect', 'Fire', 'Dynamic',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'assessed_on' => 'date',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'review_frequency_months' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function hazards(): HasMany
    {
        return $this->hasMany(RiskAssessmentHazard::class)->orderBy('sort_order')->orderBy('id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['assessed_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['assessment_type'];
    }

    /** `hse_risk`, not the `risk_assessment` the class name would produce — see Incident. */
    public function workflowEntityType(): string
    {
        return 'hse_risk';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Risk assessments';
    }

    // ------------------------------------------------------------------ state

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Past its validity date.
     *
     * An assessment with no `valid_to` never expires, rather than always expiring — the same
     * distinction `isOverdue()` makes for undated records, and worth spelling out because the naive
     * version reports every assessment as lapsed on the day it is written.
     */
    public function hasExpired(): bool
    {
        return $this->valid_to !== null && $this->valid_to->isBefore(now()->startOfDay());
    }

    public function expiresWithin(int $days): bool
    {
        return $this->valid_to !== null
            && ! $this->hasExpired()
            && $this->valid_to->isBefore(now()->startOfDay()->addDays($days));
    }

    /**
     * The worst residual score on the assessment.
     *
     * Residual and not raw: an assessment whose controls bring everything down to a 4 is a
     * different document from one that leaves a 20 standing, and the register's ranking has to say
     * so at a glance.
     */
    public function highestResidualScore(): ?int
    {
        $scores = $this->hazards->pluck('residual_score')->filter()->all();

        return $scores === [] ? null : (int) max($scores);
    }

    /**
     * The band the worst residual score falls into.
     *
     * Here rather than in the register view so that the list, the show page and any future report
     * all colour the same number the same way. The hazard model owns the thresholds; this only asks.
     */
    public function highestResidualBand(): string
    {
        return (new RiskAssessmentHazard)->band($this->highestResidualScore());
    }

    /** @param  Builder<static>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('valid_to')
            ->whereDate('valid_to', '<=', now()->startOfDay()->addDays($days))
            ->whereDate('valid_to', '>=', now()->startOfDay());
    }

    /** @param  Builder<static>  $query */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('valid_to')->whereDate('valid_to', '<', now()->startOfDay());
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        // Activity is effectively a second title — "hot work in the pipe rack" is how anybody looks
        // for this record — and it is a description of a task, not free narrative (§61).
        return ['number', 'title', 'activity', 'location'];
    }

    /** The expiry IS the deadline, which is why this register has no separate due column. */
    public function registerDueColumn(): ?string
    {
        return 'valid_to';
    }
}
