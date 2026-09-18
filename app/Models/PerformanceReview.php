<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Services\PerformanceReviewService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's review in one cycle: what they said, what their manager said, and the sign-off.
 *
 * RESTRICTED BY DEFAULT, AND THE DEFAULT IS THE POINT (§68). A review is a manager's written opinion
 * of somebody, kept for years, and read back at promotions and dismissals. The people who may open
 * it are: the person it is about, the manager who wrote it, anybody inside the owning department's
 * subtree — and holders of `performance.view_confidential`, which is what a central HR team runs on.
 * A manager in ANOTHER department, however senior, is none of those things.
 *
 * `user_id` IS WHY THE SUBJECT CAN READ THEIR OWN. Ownership does not travel up a parent relation in
 * the scope compiler, so without the subject's account on this row the one person with the strongest
 * claim to the record would be the one person unable to open it. It is the same decision
 * TrainingRecord made for the same reason.
 *
 * THE TWO ASSESSMENTS ARE SEPARATE, AND SO ARE THEIR AUTHORS. `self_*` is written only by the
 * subject and `manager_*` only by the reviewer — enforced in PerformanceReviewService, not by the
 * form, because a hidden field claiming to be somebody else's rating is exactly the thing §84 is
 * about. `self_submitted_at` freezes the first half so the manager cannot be accused of editing it.
 *
 * SIGN-OFF IS ROUTED. The completed review goes through the SAME workflow engine as everything else
 * — there is no approve() here and no approve route. What that buys is moderation: a company that
 * wants a head of department to see every rating before it is final adds a stage, and a company that
 * does not, does not.
 */
class PerformanceReview extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'self_submitted_at' => 'datetime',
            'manager_submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'overall_rating' => 'decimal:2',
        ];
    }

    public static function permissionModule(): string
    {
        return 'performance';
    }

    // ------------------------------------------------------------------ access

    /**
     * "Mine" is a review ABOUT ME, not one I typed.
     *
     * The same exception TrainingRecord makes, for the same reason: the record is about a person,
     * and that person has the strongest claim to read it. `created_by` is deliberately absent — an
     * HR administrator who opens four hundred reviews must not thereby own four hundred people's
     * ratings at `own` scope; they read the register through an organization-scoped grant, which is
     * a decision somebody made explicitly.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['user_id'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['reviewer_id'];
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Restricted;
    }

    /**
     * Structured fields only.
     *
     * `overall_rating` is here because "send anything below 2 to HR" is the one branch companies
     * actually configure. The comments are not, and never can be: a workflow condition that can test
     * the words in a manager's assessment is a way to read them back out one guess at a time (§61).
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['review_cycle_id', 'overall_rating'];
    }

    /**
     * The reference only (§61).
     *
     * Not the subject's name: a search box that matched it would tell anybody who can list reviews
     * that a named colleague has one — including, in a small company, that a review exists out of
     * cycle, which is usually the prelude to something.
     *
     * @return array<int,string>
     */
    public function registerSearchColumns(): array
    {
        return ['number'];
    }

    public function registerTitleColumn(): string
    {
        return 'number';
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }

    public static function workflowEntityLabel(): string
    {
        return 'Performance reviews';
    }

    // ------------------------------------------------------------------ workflow

    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['reviewer_id'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(PerformanceReviewService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(PerformanceReviewService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(PerformanceReviewService::class)->markReturned($this, $instance);
    }

    public function onWorkflowCancelled(WorkflowInstance $instance): void {}

    // ------------------------------------------------------------------ relations

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PerformanceReviewItem::class)->orderBy('sequence');
    }

    // ------------------------------------------------------------------ state

    public function isFinal(): bool
    {
        return in_array($this->statusKey(), ['completed', 'rejected', 'cancelled'], true);
    }

    public function isWithSubject(): bool
    {
        return $this->statusKey() === 'self_assessment';
    }

    public function isWithReviewer(): bool
    {
        return $this->statusKey() === 'manager_review';
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function subjectName(): string
    {
        return (string) ($this->employee?->fullName() ?? 'Not recorded');
    }

    public function displayName(): string
    {
        return trim($this->number.' — '.($this->cycle?->name ?? 'review'));
    }

    /** @param  Builder<static>  $query */
    public function scopeForEmployee(Builder $query, int|string|null $employeeId): Builder
    {
        return blank($employeeId) ? $query : $query->where($this->qualifyColumn('employee_id'), $employeeId);
    }

    /** @param  Builder<static>  $query */
    public function scopeForCycle(Builder $query, int|string|null $cycleId): Builder
    {
        return blank($cycleId) ? $query : $query->where($this->qualifyColumn('review_cycle_id'), $cycleId);
    }
}
