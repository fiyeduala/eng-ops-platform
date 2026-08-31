<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Enums\AssigneeType;
use App\Support\Enums\StageAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step of an approval route.
 *
 * Everything a company argues about when designing an approval process is a column here: who,
 * what they are being asked to do, how many of them must answer, how long they have, what happens
 * if they say no, and whether the author may approve their own work.
 *
 * `allow_self_approval` defaults to FALSE and that default is a security decision, not a
 * preference. Self-approval is the most common control failure in approval systems, and it usually
 * arrives by accident — the department head raises the requisition that routes to the department
 * head. Making it opt-in per stage means every instance of it is a visible choice somebody made and
 * an auditor can find.
 */
class WorkflowStage extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /** Quorum rules, as the builder offers them. */
    public const QUORUM_ANY = 'any';

    public const QUORUM_ALL = 'all';

    public const QUORUM_N_OF_M = 'n_of_m';

    /** What happens to the whole route when somebody rejects here. */
    public const ON_REJECT_TERMINATE = 'terminate';

    public const ON_REJECT_PREVIOUS = 'return_to_previous';

    public const ON_REJECT_OWNER = 'return_to_owner';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assignee_type' => AssigneeType::class,
            'action_type' => StageAction::class,
            'sequence' => 'integer',
            'quorum_count' => 'integer',
            'sla_hours' => 'integer',
            'allow_delegate' => 'boolean',
            'allow_self_approval' => 'boolean',
            'is_active' => 'boolean',
            'notify' => 'array',
            'auto_actions' => 'array',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(WorkflowStageCondition::class)->orderBy('sort');
    }

    public function stageInstances(): HasMany
    {
        return $this->hasMany(WorkflowStageInstance::class);
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

    /** Governed by `workflow.*` — a stage is part of its definition, not a module of its own. */
    public static function permissionModule(): string
    {
        return 'workflow';
    }

    public function accessOrganizationWideAbilities(): array
    {
        return ['workflow.view'];
    }

    // ------------------------------------------------------------------ helpers

    public function assigneeType(): AssigneeType
    {
        return $this->assignee_type ?? AssigneeType::Role;
    }

    public function actionType(): StageAction
    {
        return $this->action_type ?? StageAction::Approve;
    }

    public function displayName(): string
    {
        return $this->sequence.'. '.$this->name;
    }

    /**
     * How many approvals close this stage, given how many people it resolved to.
     *
     * `n_of_m` is clamped to the resolved count rather than stalling: a stage configured for three
     * of five that resolves to two people is a configuration that has drifted from the org chart,
     * and blocking the document forever is a worse answer than requiring both of them.
     */
    public function requiredApprovals(int $resolvedCount): int
    {
        if ($resolvedCount < 1) {
            return 1;
        }

        return match ($this->quorum) {
            self::QUORUM_ALL => $resolvedCount,
            self::QUORUM_N_OF_M => max(1, min((int) ($this->quorum_count ?? 1), $resolvedCount)),
            default => 1,
        };
    }

    public function quorumLabel(): string
    {
        return match ($this->quorum) {
            self::QUORUM_ALL => 'Everyone must approve',
            self::QUORUM_N_OF_M => ($this->quorum_count ?? 1).' approvals required',
            default => 'Any one approval',
        };
    }

    public function hasSla(): bool
    {
        return $this->sla_hours !== null && $this->sla_hours > 0;
    }

    /** @return array<string,string> */
    public static function quorumOptions(): array
    {
        return [
            self::QUORUM_ANY => 'Any one of them',
            self::QUORUM_ALL => 'All of them',
            self::QUORUM_N_OF_M => 'A set number of them',
        ];
    }

    /** @return array<string,string> */
    public static function rejectionOptions(): array
    {
        return [
            self::ON_REJECT_OWNER => 'Return to the owner to revise',
            self::ON_REJECT_PREVIOUS => 'Return to the previous stage',
            self::ON_REJECT_TERMINATE => 'End the approval outright',
        ];
    }
}
