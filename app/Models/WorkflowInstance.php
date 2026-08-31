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
     * An instance has no unit, no project and no owner of its own.
     *
     * Reach comes from the SUBJECT, which is the only correct answer: a person who may see the
     * document may see its approval history, and a person who may not, may not. The controllers
     * only ever load an instance through an already-authorized subject, and ApprovalPolicy asks the
     * subject's policy rather than inventing a second opinion.
     */
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
        return ['started_by'];
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
