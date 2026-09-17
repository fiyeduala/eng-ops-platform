<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Models\Concerns\SeenByLineManager;
use App\Services\TimesheetService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's week of hours, decided by the workflow engine.
 *
 * PHASE 6'S EXIT CRITERION, second half. A timesheet enters `WorkflowEngine::start()` through
 * TimesheetService::submit(), waits in My Approvals beside drawings and leave, and is decided through
 * the same endpoint. The only timesheet-specific code on the approval path is the four hooks below.
 */
class Timesheet extends Model
{
    use RegisterRecord, SeenByLineManager;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_hours' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ access

    public function accessOwnerColumns(): array
    {
        return ['created_by', 'requester_id'];
    }

    public function applyAssignedScope(Builder $query, User $user): void
    {
        $this->applyLineManagerScope($query, $user);
    }

    public function registerConditionFields(): array
    {
        return ['total_hours', 'org_unit_id'];
    }

    public function registerSearchColumns(): array
    {
        return ['number'];
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }

    public static function workflowEntityLabel(): string
    {
        return 'Timesheets';
    }

    // ------------------------------------------------------------------ workflow

    /** The person whose hours these are — see LeaveRequest::workflowOwnerId() for why not `created_by`. */
    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['requester_id'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(TimesheetService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(TimesheetService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(TimesheetService::class)->markReturned($this, $instance);
    }

    public function onWorkflowCancelled(WorkflowInstance $instance): void {}

    // ------------------------------------------------------------------ relations

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class)->orderBy('line')->orderBy('work_date');
    }

    // ------------------------------------------------------------------ presentation

    /** Number, person and week. Descriptions of the work stay on the sheet (§68). */
    public function displayName(): string
    {
        $this->loadMissing('employee');

        return trim($this->number.' — '.($this->employee?->fullName() ?? 'Timesheet').', '.$this->periodLabel());
    }

    public function periodLabel(): string
    {
        if ($this->period_start === null || $this->period_end === null) {
            return '';
        }

        return 'week of '.$this->period_start->format('j M Y');
    }
}
