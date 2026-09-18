<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person, one period.
 *
 * THE EMPLOYEE READS THEIR OWN AND NOBODY ELSE'S — including their own line manager's, and
 * including their own team's if they manage one. This is the sharpest confidentiality rule in the
 * platform: `AccessScope::Own` is the only scope that reaches a payslip without
 * `payroll.view_confidential`, and because Own never inherits through a parent relation, the payslip
 * carries its own `user_id` rather than borrowing the employee's. That limit is what stops a
 * department head's reach over their people from becoming reach over their people's pay.
 *
 * `skipped_reason` answers the worst failure mode payroll has. Somebody with no salary row must
 * never be quietly paid zero, and must never be silently dropped from the run either — either way
 * the first person to notice is the employee, on pay day, when nothing arrives. So they get a
 * payslip that pays nothing and says why, and the omission is visible on the register while there
 * is still time to fix it.
 *
 * `period_fraction` is how much of the period they were actually employed for: 1 for everybody who
 * was there throughout, less for a starter or a leaver. Stored rather than recomputed, because the
 * dates on the personnel record can change afterwards and a payslip must always explain the figure
 * it actually paid.
 */
class Payslip extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'basic_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'pensionable_amount' => 'decimal:2',
            'relief_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'employer_cost_amount' => 'decimal:2',
            'period_fraction' => 'decimal:6',
            'overtime_hours' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The login the payslip belongs to, which is how `own` reaches it. Null for staff with no account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sequence')->orderBy('id');
    }

    // ------------------------------------------------------------------ access

    /**
     * Reached through the person it pays, and by almost nobody else.
     *
     * The parent relation carries org unit and project so that the wider scopes — team, unit,
     * organization — resolve against the personnel record rather than being duplicated here. It does
     * NOT carry ownership: `AccessScope::Own` never inherits through a parent relation, which is
     * precisely why this table has a `user_id` of its own (see `accessOwnerColumns()` below).
     *
     * Reading somebody ELSE's payslip takes `payroll.view` at a scope wider than `own` — which only
     * HR and the executive hold — and then gate 5 on top. A department head's `employee.view` over
     * their whole team buys them nothing here at all: managing somebody has never implied being
     * allowed to see what they earn, and that is the sharpest confidentiality rule in the platform.
     */
    public function accessParentRelation(): ?string
    {
        return 'employee';
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /**
     * The person the payslip pays, copied onto the payslip at calculation time.
     *
     * THIS COLUMN IS THE WHOLE OF "MY PAYSLIPS". `Own` resolves against the record's own table and
     * cannot reach the employee through `accessParentRelation` — the same limit that keeps
     * `employee_movement.view => own` out of the self-service role, because there it would show
     * somebody the transfers they RAISED rather than the transfer happening to them. Returning `[]`
     * here would leave every ordinary employee with an empty payslip list and no error to explain it.
     *
     * It is null for an employee with no login, which correctly means nobody reaches this row by
     * owning it.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['user_id'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ behaviour

    public function wasSkipped(): bool
    {
        return $this->skipped_reason !== null;
    }

    /** A payslip is frozen exactly when its run is. */
    public function isLocked(): bool
    {
        return $this->run?->isLocked() ?? false;
    }

    /** Number and person — never a figure, because §68 applies to payroll more than anywhere. */
    public function displayName(): string
    {
        return trim($this->number.' — '.($this->employee?->display_name ?? 'Employee'));
    }

    /** @param  Builder<static>  $query */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNull('skipped_reason');
    }

    /** @param  Builder<static>  $query */
    public function scopeSkipped(Builder $query): Builder
    {
        return $query->whereNotNull('skipped_reason');
    }
}
