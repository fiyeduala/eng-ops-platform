<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Support\Enums\Confidentiality;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What somebody is paid, from when.
 *
 * NEVER UPDATED IN PLACE. A raise is a new row, and last March's payslip was calculated on the row
 * that was in force last March. A single `salary` column on the employee record would destroy the
 * only evidence of what somebody was actually owed in a past period — which is the only question
 * anybody ever asks payroll about a past period.
 *
 * RESTRICTED BY DEFAULT, and not by accident. A person's own line manager holds `employee.view`
 * over their whole department and must not thereby learn what any of them earn; reaching this
 * register needs `payroll.view_confidential`, which is a permission somebody granted on purpose.
 *
 * `hours_per_period` is the divisor that turns a periodic salary into an hourly figure for overtime.
 * It is data rather than a constant because companies genuinely disagree about it — a contracted
 * weekly figure in one place, a conventional monthly number in another — and §47 puts exactly that
 * kind of disagreement in the database instead of in an if-statement.
 */
class EmployeeSalary extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    /** How often the `basic_amount` is paid. The company's own list (§47). */
    public const FREQUENCIES = [
        'monthly' => 'Monthly',
        'weekly' => 'Weekly',
        'daily' => 'Daily',
        'hourly' => 'Hourly',
        'annual' => 'Annual',
    ];

    /** How many pay periods of each frequency fall in a year. Arithmetic, not policy. */
    private const PERIODS_PER_YEAR = [
        'monthly' => 12,
        'weekly' => 52,
        'annual' => 1,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'basic_amount' => 'decimal:2',
            'hours_per_period' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ------------------------------------------------------------------ access

    /**
     * Read through the person it pays — but gated far more tightly than the personnel record.
     *
     * Inheriting reach from the employee is what makes "my own salary" work without a special case.
     * What stops it becoming "my department's salaries" is the confidentiality above, which the
     * `employee.view` a department head holds does nothing to satisfy.
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

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ behaviour

    /** Whether this row was the one in force on a given day. */
    public function coversDate(Carbon $date): bool
    {
        if ($this->effective_from->isAfter($date)) {
            return false;
        }

        return $this->effective_to === null || ! $this->effective_to->isBefore($date);
    }

    /**
     * The basic converted to the run's period.
     *
     * An annual figure over a monthly run is a twelfth; a weekly figure over a monthly run is not a
     * quarter, which is why weeks convert through the year rather than through the month. Daily and
     * hourly salaries have no fixed period figure at all — how much somebody on a day rate earns in
     * a month is a question about days worked, not about arithmetic — so they return null and the
     * caller must supply the quantity.
     */
    public function amountForFrequency(string $frequency): ?BigDecimal
    {
        $mine = self::PERIODS_PER_YEAR[$this->frequency] ?? null;
        $theirs = self::PERIODS_PER_YEAR[$frequency] ?? null;

        if ($mine === null || $theirs === null) {
            return null;
        }

        return BigDecimal::of($this->basic_amount)
            ->multipliedBy($mine)
            ->dividedBy($theirs, 2, RoundingMode::HALF_UP);
    }

    /**
     * The hourly figure used to price overtime.
     *
     * Null when the company has not said how many hours the salary covers, and null is the right
     * answer rather than a guess: inventing a divisor here would silently pay everybody's overtime
     * at a rate nobody chose. The caller reports the gap on the payslip instead.
     */
    public function hourlyRate(): ?BigDecimal
    {
        if ($this->frequency === 'hourly') {
            return BigDecimal::of($this->basic_amount);
        }

        $hours = $this->hours_per_period;

        if ($hours === null || BigDecimal::of($hours)->isLessThanOrEqualTo(0)) {
            return null;
        }

        return BigDecimal::of($this->basic_amount)->dividedBy($hours, 4, RoundingMode::HALF_UP);
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? $this->frequency;
    }

    /**
     * Whether a locked payroll run has already paid somebody against this row.
     *
     * Asked before deletion. A locked run is one that was approved and actually paid, and removing
     * the salary underneath it would leave payslips naming a figure with nothing behind them — the
     * exact state in which a pay query from six months ago becomes unanswerable.
     *
     * Only locked runs count. An open run recalculates from scratch every time, so a salary row a
     * draft run happens to have touched is not evidence of anything yet.
     */
    public function hasBeenPaidAgainst(): bool
    {
        return Payslip::query()
            ->where('employee_id', $this->employee_id)
            ->whereHas('run', fn (Builder $q) => $q->whereNotNull('locked_at')
                ->whereDate('period_end', '>=', $this->effective_from)
                ->when($this->effective_to !== null,
                    fn (Builder $r) => $r->whereDate('period_start', '<=', $this->effective_to)))
            ->exists();
    }

    /**
     * The row in force on a day.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInForceOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
