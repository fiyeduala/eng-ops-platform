<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule deciding whether a stage RUNS at all (§20).
 *
 * "Anything over 50,000 also goes to the MD." "Safety documents also go to HSE." A condition is
 * DATA — a field, an operator and a value — never an expression. ConditionEvaluator whitelists
 * which fields may be read per entity type and refuses anything else, because a configuration
 * screen that can evaluate arbitrary expressions is a remote shell with a nice form around it.
 *
 * A stage whose conditions come out false is recorded as SKIPPED with a reason, never silently
 * omitted. "Why did this not go to the MD" is a question the approval history has to answer.
 */
class WorkflowStageCondition extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /** The operators ConditionEvaluator implements. Anything else is a configuration error. */
    public const OPERATORS = [
        '=' => 'is',
        '!=' => 'is not',
        '>' => 'is greater than',
        '>=' => 'is at least',
        '<' => 'is less than',
        '<=' => 'is at most',
        'in' => 'is one of',
        'not_in' => 'is not one of',
        'contains' => 'contains',
        'between' => 'is between',
        'is_null' => 'is empty',
        'changed' => 'has changed',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
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

    public static function permissionModule(): string
    {
        return 'workflow';
    }

    public function accessOrganizationWideAbilities(): array
    {
        return ['workflow.view'];
    }

    // ------------------------------------------------------------------ helpers

    /** Operators that take no value, so the builder hides the value input. */
    public function needsValue(): bool
    {
        return $this->operator !== 'is_null' && $this->operator !== 'changed';
    }

    public function displayName(): string
    {
        $operator = self::OPERATORS[$this->operator] ?? $this->operator;

        return trim(str_replace('_', ' ', (string) $this->field).' '.$operator.' '.(string) $this->value);
    }
}
