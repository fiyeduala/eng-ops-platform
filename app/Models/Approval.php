<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Enums\Decision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's decision. Separate from the stage instance because a quorum stage collects several,
 * and because "who actually pressed approve" must survive any later recalculation of what the stage
 * as a whole concluded.
 *
 * The IP and user agent are stored on the row itself rather than left to the audit log. An approval
 * is the single record in this system most likely to be disputed years later, and the answer to
 * "was that really me" should not depend on a general-purpose log that may have been rotated.
 *
 * `delegated_from` keeps the chain of authority legible: acting on somebody's behalf is a normal
 * thing to do and an abnormal thing to hide.
 */
class Approval extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'acted_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function stageInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowStageInstance::class, 'workflow_stage_instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    // ------------------------------------------------------------------ access hooks

    /** My own decision is mine. Reach to anyone else's comes through the record it was made on. */
    public function accessOwnerColumns(): array
    {
        return ['user_id'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['user_id'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The decision, never null.
     *
     * Named away from the `decision` column on purpose: a method sharing an attribute's name reads
     * as an accessor to the next person to open this file, and it is not one.
     */
    public function resolvedDecision(): Decision
    {
        return $this->decision ?? Decision::Abstain;
    }

    public function wasDelegated(): bool
    {
        return $this->delegated_from !== null;
    }

    public function displayName(): string
    {
        return ($this->user?->name ?? 'Someone').' — '.$this->resolvedDecision()->label();
    }
}
