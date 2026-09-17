<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Enums\StageAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stage, live, on one record. This is the row that puts something in somebody's inbox.
 *
 * `resolved_assignees` is the heart of it: the ids resolved when the stage was ENTERED, frozen.
 * The policy checks THIS LIST, never a re-resolution at action time. If somebody is moved out of a
 * department while a document sits in their inbox they keep the decision they were legitimately
 * given — re-resolving per request would let an afternoon's org-chart edit silently retract an
 * assignment mid-flight, which is both astonishing to the user and a denial of service on
 * approvals. It is also what makes the audit trail honest: this is who was asked, whatever the
 * org chart says today.
 *
 * `stage_name` is a snapshot for the same reason a signature snapshots a designation. Renaming
 * "Technical Review" to "Discipline Check" next year must not rewrite what the history says
 * happened last year.
 */
class WorkflowStageInstance extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resolved_assignees' => 'array',
            'attempt' => 'integer',
            'sequence' => 'integer',
            'last_escalated_level' => 'integer',
            'entered_at' => 'datetime',
            'due_at' => 'datetime',
            'acted_at' => 'datetime',
            'last_escalated_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return ['acted_by'];
    }

    /**
     * `assigned` means the frozen assignee list — the stages a person was actually asked to decide.
     *
     * Without this the stage has no assignee column, so an `assigned` grant would fall back to
     * `acted_by` alone and a person could never reach a stage before deciding it.
     *
     * @param  Builder<static>  $query
     */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $query->orWhere(fn (Builder $q) => $this->scopeAssignedTo($q, $user));
    }

    public static function permissionModule(): string
    {
        return 'approval';
    }

    // ------------------------------------------------------------------ query scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /**
     * The approvals inbox, as one predicate (docs/07 §8).
     *
     * JSON containment is expressed as a LIKE over the serialised array rather than a
     * driver-specific JSON function, because MySQL's JSON_CONTAINS and SQLite's json_each are not
     * interchangeable and the inbox has to work identically in both. The delimiters around the id
     * matter: without them, user 1 would match the stored id 11.
     *
     * The result is still filtered by the subject's own policy before anything is shown — this
     * narrows the query, it does not decide access (§84).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        $id = (int) $user->getKey();

        return $query->where(fn (Builder $q) => $q
            ->where('resolved_assignees', 'like', '['.$id.',%')
            ->orWhere('resolved_assignees', 'like', '%,'.$id.',%')
            ->orWhere('resolved_assignees', 'like', '%,'.$id.']')
            ->orWhere('resolved_assignees', '=', '['.$id.']'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<int,int> */
    public function assigneeIds(): array
    {
        return array_values(array_map('intval', (array) ($this->resolved_assignees ?? [])));
    }

    /**
     * Is this person one of the people the stage was given to?
     *
     * The authoritative check, used by ApprovalPolicy. Deliberately reads the frozen list and
     * nothing else — no fallback to "well, they hold the role now", because that is precisely the
     * re-resolution the freeze exists to prevent.
     */
    public function isAssignedTo(User $user): bool
    {
        return in_array((int) $user->getKey(), $this->assigneeIds(), true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function actionType(): StageAction
    {
        return $this->stage?->actionType() ?? StageAction::Approve;
    }

    /** How many people have already recorded a decision here. */
    public function decisionCount(): int
    {
        return $this->relationLoaded('approvals')
            ? $this->approvals->count()
            : $this->approvals()->count();
    }

    public function hasDecisionFrom(User $user): bool
    {
        return $this->relationLoaded('approvals')
            ? $this->approvals->contains('user_id', $user->getKey())
            : $this->approvals()->where('user_id', $user->getKey())->exists();
    }

    public function displayName(): string
    {
        return $this->stage_name ?? ('Stage '.$this->sequence);
    }
}
