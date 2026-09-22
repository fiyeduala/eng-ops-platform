<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One record's journey through one workflow.
 *
 * Points at its subject through a morph, and that is the entire reason the engine is reusable:
 * nothing in this table knows what a document is. When purchase requests arrive in Phase 5 they
 * become subjects of these same rows.
 *
 * RESUBMISSION uses `attempt`, not a new instance (§24). A rejected document that is fixed and sent
 * back round gets attempt + 1 here, keeping every earlier stage instance, decision, comment and
 * timing attached to the same conversation. A fresh instance per attempt would scatter "what were
 * we asked to change last time, and by whom" across rows nothing joins.
 *
 * `stalled` is a first-class status, not an error swept aside. It is what happens when a stage
 * resolves to nobody, and the engine stops and says so. A workflow that silently skips the approval
 * it could not find anybody for is far more dangerous than one that halts and asks for help.
 */
class WorkflowInstance extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_STALLED = 'stalled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /**
     * Every stage this record has been through, in the order it went through them.
     *
     * THE ORDER IS PART OF THE RELATION because that is how the history is read everywhere it is
     * displayed, and a history that comes back shuffled is worse than useless.
     *
     * The cost of that convenience: `orderBy` on a query built from this relation is APPENDED, not
     * substituted, so `stageInstances()->orderByDesc('sequence')` sorts by `attempt, sequence,
     * sequence DESC` and still hands back the FIRST row. That silently returned the earliest
     * approver as "who approved this document", and reopened the earliest stage rather than the
     * previous one on a rejection. Any caller looking for the LATEST anything must call
     * `->reorder()` first, and both callers that do are commented accordingly.
     */
    public function stageInstances(): HasMany
    {
        return $this->hasMany(WorkflowStageInstance::class)->orderBy('attempt')->orderBy('sequence');
    }

    public function currentStageInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowStageInstance::class, 'current_stage_instance_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * Where the subject lived when the route last moved, copied onto the instance.
     *
     * REACH STILL COMES FROM THE SUBJECT — that is the only correct answer, since a person who may
     * see the document may see its approval history and a person who may not, may not. What changed
     * is how the compiler is told. These used to return null, and because a subject hangs off a
     * polymorphic pair that `whereHas()` cannot walk, "no column and no parent" meant ScopeCompiler
     * fell through to `1 = 0`: every unit- or project-scoped `approval.*` grant in the system
     * reached nothing at all, silently. WorkflowEngine now writes the subject's own unit and project
     * here on start and on every advance, so the question has an indexed answer and a record that
     * moves department mid-route takes its approval with it (§74).
     *
     * Null is a legitimate value: a subject with no unit — organization-level reference data — has
     * an approval that no unit scope selects, which is the truthful answer rather than a guess.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return 'org_unit_id';
    }

    public function accessProjectColumn(): ?string
    {
        return 'project_id';
    }

    public function accessOwnerColumns(): array
    {
        return ['started_by'];
    }

    /**
     * An instance is "assigned to me" when one of its stages is, or was.
     *
     * Without this the trait's default looks for an `assignee_id` column that this table does not
     * have. That matters more than it sounds: declaring a parent relation on WorkflowStageInstance
     * switches on `applyParentProtection`, which re-asks `approval.view` against THIS model for
     * every stage the inbox lists — so a reviewer whose only grant is `approval.view` at `assigned`
     * (which is what BASELINE gives everybody) would be filtered out of their own inbox by a
     * predicate about a table column. Past tense is deliberate: `assignedTo` is not restricted to
     * pending stages, because somebody who approved something last month must still be able to open
     * what they approved.
     *
     * @param  Builder<static>  $query
     */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $query->orWhereHas('stageInstances', fn (Builder $stage) => $stage->assignedTo($user));
    }

    public static function permissionModule(): string
    {
        return 'approval';
    }

    // ------------------------------------------------------------------ query scopes

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    // ------------------------------------------------------------------ helpers

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isStalled(): bool
    {
        return $this->status === self::STATUS_STALLED;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /** The stage instances belonging to the attempt currently in progress. */
    public function currentAttemptStages(): HasMany
    {
        return $this->stageInstances()->where('attempt', $this->attempt);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => 'In progress',
            self::STATUS_COMPLETED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_REVISION_REQUESTED => 'Revision requested',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_STALLED => 'Stalled — no assignee found',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function displayName(): string
    {
        return ($this->workflow?->name ?? 'Approval').' — attempt '.$this->attempt;
    }
}
