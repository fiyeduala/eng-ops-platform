<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Services\LeaveService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time off, asked for by one person and decided by the workflow engine.
 *
 * THIS IS PHASE 6'S EXIT CRITERION. A leave request enters the same `WorkflowEngine::start()` a
 * document does, waits in the same My Approvals inbox, and is decided through the same endpoint.
 * The only leave-specific thing about its approval is what happens when the route finishes, and
 * that is the four `onWorkflow*` hooks below handing over to LeaveService.
 *
 * THE OWNER IS THE PERSON TAKING THE LEAVE, not whoever typed it. HR entering a request for
 * somebody who is away from a desk must not make HR's own line manager the approver, so
 * `workflowOwnerId()` answers with the requester — which is what the default "Supervisor" stage and
 * the self-approval rule both read.
 */
class LeaveRequest extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'start_half_day' => 'boolean',
            'end_half_day' => 'boolean',
            'days' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ access

    public function accessOwnerColumns(): array
    {
        return ['created_by', 'requester_id'];
    }

    /**
     * "Assigned to me" for leave is the leave of the people who report to me.
     *
     * The baseline grants `leave.view` at `assigned` for this reason: the default route sends a
     * request to the requester's supervisor, and the approvals inbox drops any row whose subject the
     * approver may not open. Without this a line manager would be asked to decide a request they
     * could not see — and the inbox, correctly, would hide it from them.
     */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return;
        }

        $query->orWhereIn(
            $this->qualifyColumn('employee_id'),
            Employee::query()->select('id')->where('supervisor_id', $employeeId),
        );
    }

    public function registerConditionFields(): array
    {
        return ['leave_type_id', 'days', 'org_unit_id'];
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
        return 'Leave requests';
    }

    // ------------------------------------------------------------------ workflow

    /**
     * No fallback to `created_by`. Somebody with no sign-in whose leave HR types in has no owner,
     * and a "Supervisor" stage then stalls where an administrator can see it and reassign it —
     * which beats quietly routing the request to HR's own line manager.
     */
    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['requester_id'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function requiresWorkflow(): bool
    {
        $this->loadMissing('leaveType');

        return $this->leaveType?->requires_approval !== false;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(LeaveService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(LeaveService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(LeaveService::class)->markReturned($this, $instance);
    }

    /** Cancelling the route is done BY LeaveService::cancel(), which has already moved the status. */
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function handoverTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handover_to');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ------------------------------------------------------------------ presentation

    /**
     * Number, person and dates — never the reason (§68).
     *
     * This is the label the approvals inbox and every approval notification carry, and a reason for
     * leave is often medical or family business the requester told their manager and nobody else.
     */
    public function displayName(): string
    {
        $this->loadMissing('employee');

        return trim($this->number.' — '.($this->employee?->fullName() ?? 'Leave').', '.$this->periodLabel());
    }

    public function periodLabel(): string
    {
        if ($this->start_date === null || $this->end_date === null) {
            return '';
        }

        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->format('j M Y').($this->start_half_day || $this->end_half_day ? ' (half day)' : '');
        }

        return $this->start_date->format('j M').' – '.$this->end_date->format('j M Y');
    }
}
