<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Services\EmployeeMovementService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promotion, a transfer, a secondment — asked for, agreed, and then applied on the day it says.
 *
 * THIS TABLE IS THE PERSONNEL HISTORY. The employee record holds where somebody is now; every
 * movement holds where they were and where they went, so "who approved moving her to Lagos, and
 * when" has an answer a year later. A module that only overwrote `position_id` would answer it with
 * an audit-log diff, if anybody thought to keep one, and with nothing at all about who agreed.
 *
 * AGREED AND APPLIED ARE DIFFERENT DAYS. A promotion signed off in September to take effect on 1
 * January must not move anybody in September. `decided_at` is when the route finished; `applied_at`
 * is when the personnel record was actually rewritten, which happens on or after `effective_on` and
 * exactly once. Both timestamps exist because merging them is how people's job titles start changing
 * the moment a director clicks a button.
 *
 * A NULL `to_` COLUMN MEANS UNCHANGED, NOT CLEARED. A promotion that leaves somebody in the same
 * department sets only the position, and EmployeeMovementService::apply() writes only the columns
 * that were actually asked for. The alternative — treating null as "set to null" — would quietly
 * empty somebody's department every time a manager filled in one field.
 *
 * RESTRICTED BY DEFAULT. "Musa is being moved out of Piping" is a fact about Musa before it is a
 * fact about the department, and it is frequently decided weeks before he is told.
 */
class EmployeeMovement extends Model
{
    use RegisterRecord;

    /** The company's own vocabulary (§47). A starting list, not a closed one. */
    public const DEFAULT_KINDS = ['promotion', 'transfer', 'secondment', 'acting', 'regrade'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'ends_on' => 'date',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ access

    /**
     * Read through the person it moves.
     *
     * The row has no department of its own — it has two, a from and a to, and neither is "the unit
     * that owns this record" — so reach is inherited from the personnel record exactly as an exit's
     * is. Seeing that somebody in your department is being transferred requires being able to see
     * them; reading it from outside requires `employee_movement.view_confidential`.
     */
    public function accessParentRelation(): ?string
    {
        return 'employee';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['requester_id', 'created_by'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * NOT `org_unit_id`, which this table does not have.
     *
     * Left at the trait default, the scope compiler would emit SQL naming a column that does not
     * exist — a 500 on the index page for precisely the users whose grant is department-scoped. Null
     * sends both the unit scope and the restricted branch of Gate 5 up through the employee instead.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Restricted;
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['kind', 'to_org_unit_id', 'to_position_id'];
    }

    /** The reference only — not the reason, and not the person (§61). */
    public function registerSearchColumns(): array
    {
        return ['number'];
    }

    public function registerTitleColumn(): string
    {
        return 'kind';
    }

    public function registerDueColumn(): ?string
    {
        return 'effective_on';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Promotions & transfers';
    }

    // ------------------------------------------------------------------ workflow

    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['requester_id'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(EmployeeMovementService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(EmployeeMovementService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(EmployeeMovementService::class)->markReturned($this, $instance);
    }

    public function onWorkflowCancelled(WorkflowInstance $instance): void {}

    // ------------------------------------------------------------------ relations

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'from_position_id')->withTrashed();
    }

    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'to_position_id')->withTrashed();
    }

    public function fromOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'from_org_unit_id');
    }

    public function toOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'to_org_unit_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function fromSupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_supervisor_id');
    }

    public function toSupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_supervisor_id');
    }

    // ------------------------------------------------------------------ state

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function isApproved(): bool
    {
        return in_array($this->statusKey(), ['approved', 'applied'], true);
    }

    /** Approved, not yet applied, and the date has arrived. What the daily sweep looks for. */
    public function isDueToApply(): bool
    {
        return $this->statusKey() === 'approved'
            && $this->applied_at === null
            && $this->effective_on !== null
            && ! $this->effective_on->isAfter(now()->startOfDay());
    }

    /** What actually changes, in words, for the screen and the timeline. */
    public function changeSummary(): array
    {
        $changes = [];

        if ($this->to_position_id !== null && $this->to_position_id !== $this->from_position_id) {
            $changes[] = 'Position: '.($this->fromPosition?->title ?? '—').' → '.($this->toPosition?->title ?? '—');
        }

        if ($this->to_org_unit_id !== null && $this->to_org_unit_id !== $this->from_org_unit_id) {
            $changes[] = 'Department: '.($this->fromOrgUnit?->name ?? '—').' → '.($this->toOrgUnit?->name ?? '—');
        }

        if ($this->to_location_id !== null && $this->to_location_id !== $this->from_location_id) {
            $changes[] = 'Location: '.($this->fromLocation?->name ?? '—').' → '.($this->toLocation?->name ?? '—');
        }

        if ($this->to_supervisor_id !== null && $this->to_supervisor_id !== $this->from_supervisor_id) {
            $changes[] = 'Reports to: '.($this->fromSupervisor?->fullName() ?? '—').' → '.($this->toSupervisor?->fullName() ?? '—');
        }

        return $changes;
    }

    public function kindLabel(): string
    {
        return ucfirst(str_replace('_', ' ', (string) $this->kind));
    }

    public function displayName(): string
    {
        return trim($this->number.' — '.$this->kindLabel());
    }

    /** @param  Builder<static>  $query */
    public function scopeForEmployee(Builder $query, int|string|null $employeeId): Builder
    {
        return blank($employeeId) ? $query : $query->where($this->qualifyColumn('employee_id'), $employeeId);
    }

    /** @param  Builder<static>  $query */
    public function scopeOfKind(Builder $query, ?string $kind): Builder
    {
        return blank($kind) ? $query : $query->where($this->qualifyColumn('kind'), $kind);
    }
}
