<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Models\Concerns\SeenByLineManager;
use App\Services\OvertimeService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission to work extra hours, asked for before the work and decided by the workflow engine.
 *
 * Once approved and the day has come, the person records how many of the approved hours they
 * actually worked. Payroll reads `payableHours()` — never more than was approved.
 */
class OvertimeRequest extends Model
{
    use RegisterRecord, SeenByLineManager;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'worked_hours' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'overtime';
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
        return ['hours', 'org_unit_id', 'project_id'];
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
        return 'Overtime requests';
    }

    // ------------------------------------------------------------------ workflow

    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['requester_id'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(OvertimeService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(OvertimeService::class)->markRejected($this, $instance);
    }

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(OvertimeService::class)->markReturned($this, $instance);
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

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ------------------------------------------------------------------ presentation

    /** Hours the company owes for: what was worked, capped at what was approved; null until recorded. */
    public function payableHours(): ?string
    {
        if ($this->statusKey() !== 'approved' || $this->worked_hours === null) {
            return null;
        }

        return (string) $this->worked_hours;
    }

    /** Number, person, date and hours — never the reason (§68). */
    public function displayName(): string
    {
        $this->loadMissing('employee');

        return trim($this->number.' — '.($this->employee?->fullName() ?? 'Overtime').', '
            .$this->work_date?->format('j M Y').' ('.$this->hours.'h)');
    }
}
