<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Services\PayrollService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One period's pay, for everybody, decided once.
 *
 * APPROVAL LOCKS IT, AND NOTHING UNLOCKS IT. `locked_at` is set when the route finishes and after
 * that no payslip in the run may be touched. A mistake found afterwards is corrected by a NEW run
 * that points back at this one through `corrects_payroll_run_id`, so the wrong figure and the
 * correction are both on the record. That is §59 applied to money: a payroll history that can be
 * tidied up afterwards is worth nothing to an auditor, and worth less than nothing to the person
 * arguing they were underpaid.
 *
 * THERE IS NO `approve()` ON THIS MODEL AND NO APPROVE ROUTE. A payroll run is decided in the same
 * inbox, by the same engine, as a drawing, a leave request, a timesheet and a promotion — which is
 * the exit criterion the whole of Phase 6 exists to satisfy. A second approval path for money would
 * be the most tempting one to build and the worst one to have (§84).
 *
 * THE TOTALS ARE A CACHE, rebuilt from the payslips whenever the run is calculated. They exist so
 * that a register listing twenty runs does not sum a hundred thousand payslip lines to draw itself
 * (§89). They are never the source of truth and never written by hand.
 *
 * RESTRICTED BY DEFAULT. A run is the whole company's pay in one object; there is no scope narrow
 * enough to make it ordinarily readable, so reaching it at all requires `payroll.view_confidential`.
 */
class PayrollRun extends Model
{
    use RegisterRecord;

    /** The company's own list (§47) — a starting point, not a closed set. */
    public const FREQUENCIES = [
        'monthly' => 'Monthly',
        'weekly' => 'Weekly',
        'fortnightly' => 'Fortnightly',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'gross_total' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'employer_cost_total' => 'decimal:2',
            'payslip_count' => 'integer',
            'confidentiality' => Confidentiality::class,
            'calculated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** The run this one exists to correct, if it is a correction. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_payroll_run_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_payroll_run_id');
    }

    // ------------------------------------------------------------------ access

    /**
     * Company-wide: no department, no project, no parent to inherit from.
     *
     * Deliberately not reachable through the employees it pays. A run is not a fact about one
     * person — inheriting from any of them would make it readable by anybody who could see any one
     * of the people in it, which is everybody.
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
        return ['created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ workflow
    //
    // THE FOUR HOOKS BELOW ARE WHAT MAKES THE APPROVAL REAL, and `HasWorkflow` defines all of them
    // as no-ops. A register that routes correctly but does not override them is the worst failure
    // available here, because everything looks right: the run submits, the approver gets it in their
    // inbox, they approve it, the stage closes, the instance completes — and the run is still sitting
    // in `submitted` with nothing locked. Nobody would be paid, and no error would say why.
    //
    // They are on the model rather than the service because the engine holds a subject, not a
    // register. That is how the same engine decides a drawing, a leave request and this without
    // knowing any of them exists (§Phase 6 exit criterion).

    /** The clerk who opened the run, so the route runs on their line manager. */
    public function workflowOwnerId(): ?int
    {
        $owner = $this->attributes['created_by'] ?? null;

        return $owner === null ? null : (int) $owner;
    }

    public function onWorkflowApproved(WorkflowInstance $instance): void
    {
        app(PayrollService::class)->markApproved($this, $instance);
    }

    public function onWorkflowRejected(WorkflowInstance $instance): void
    {
        app(PayrollService::class)->markRejected($this, $instance);
    }

    /**
     * Sent back for changes.
     *
     * The run returns to `draft` and becomes editable again — which is right, because the figures
     * are what was queried. Recalculating it replaces every payslip, and nothing has been locked,
     * so there is nothing here to undo.
     */
    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void
    {
        app(PayrollService::class)->markReturned($this, $instance);
    }

    public function onWorkflowCancelled(WorkflowInstance $instance): void {}

    // ------------------------------------------------------------------ behaviour

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Whether the figures may still be changed.
     *
     * Asked before every write in the service. A run that has been submitted is already in
     * somebody's inbox and recalculating it underneath them would mean the figures approved were
     * not the figures reviewed.
     */
    public function isEditable(): bool
    {
        return ! $this->isLocked() && in_array($this->statusKey(), ['draft', 'calculated', 'rejected'], true);
    }

    public function isCorrection(): bool
    {
        return $this->corrects_payroll_run_id !== null;
    }

    public function periodLabel(): string
    {
        return $this->period_start?->format('j M Y').' – '.$this->period_end?->format('j M Y');
    }

    /** Number, name and period — never a figure (§68). */
    public function displayName(): string
    {
        return trim($this->number.' — '.$this->name);
    }

    public function registerSearchColumns(): array
    {
        return ['number', 'name'];
    }

    public function registerTitleColumn(): string
    {
        return 'name';
    }

    /** @param  Builder<static>  $query */
    public function scopeCoveringDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date);
    }
}
