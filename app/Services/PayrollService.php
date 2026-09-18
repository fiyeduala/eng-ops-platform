<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\Employee;
use App\Models\EmployeePayComponent;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Models\WorkflowInstance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opening, calculating, routing and locking a period's pay.
 *
 * THERE IS NO `approve()` BELOW, AND THERE IS NO APPROVE ROUTE. A payroll run is decided in the
 * same inbox as a drawing, a leave request, a timesheet and a promotion, by the same engine, and
 * this class learns the outcome through `afterApproved()` like every other routed register. Money
 * is the place where a second, more convenient approval path is most tempting to build and most
 * dangerous to have (§84), so payroll gets exactly the same one as everything else — which is the
 * claim the whole of Phase 6 exists to make good.
 *
 * CALCULATION IS REPEATABLE AND DESTRUCTIVE, UNTIL IT IS NOT. While a run is open it may be
 * recalculated any number of times and each pass throws away the previous payslips: half-stale
 * figures from a previous attempt are worse than no figures. Once approved, `locked_at` is set and
 * nothing may be recalculated, edited or deleted ever again — a correction is a NEW run pointing
 * back at this one (§59).
 *
 * THE INPUTS COME FROM THE REST OF THE PLATFORM AND ARE NEVER RE-KEYED. Approved overtime hours
 * come from the overtime register, unpaid leave from the leave register, joining and leaving dates
 * from the personnel record. If any of that could be typed into payroll by hand, the figures could
 * disagree with the records they came from, and the payslip would stop being evidence of anything.
 */
class PayrollService extends RoutedRecordService
{
    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        SettingsRepository $settings,
        WorkflowEngine $workflow,
        protected readonly PayrollCalculator $calculator,
        protected readonly LeaveService $leave,
    ) {
        parent::__construct($numbers, $statuses, $activity, $settings, $workflow);
    }

    protected function entityType(): string
    {
        return 'payroll_run';
    }

    protected function modelClass(): string
    {
        return PayrollRun::class;
    }

    protected function noun(): string
    {
        return 'payroll run';
    }

    protected function protectedAttributes(): array
    {
        return [
            ...parent::protectedAttributes(),
            // Every one of these is written by the act of calculating or deciding, never by a form.
            // A gross total that could be posted from a browser is a gross total that means nothing.
            'gross_total', 'deduction_total', 'net_total', 'employer_cost_total', 'payslip_count',
            'calculated_at', 'locked_at',
        ];
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var PayrollRun $record */
        return $actor->name.' opened '.$record->name.' for '.$record->periodLabel().'.';
    }

    // ------------------------------------------------------------------ calculating

    /**
     * Work out every payslip in the run.
     *
     * Everybody who was employed for any part of the period gets a payslip, including somebody who
     * left on the second day — they are owed two days. Anybody the run cannot pay gets a payslip
     * that pays nothing and SAYS WHY, because the alternative failure modes are both silent: paying
     * them zero, or dropping them from the run. In either case the first person to notice is the
     * employee, on pay day, when nothing arrives.
     */
    public function calculate(PayrollRun $run, User $actor): PayrollRun
    {
        $this->requireEditable($run, 'recalculated');

        return DB::transaction(function () use ($run, $actor) {
            // Destructive on purpose. A second pass that merged into the first would leave figures
            // from a superseded structure sitting beside fresh ones, and nothing on the payslip
            // would say which was which.
            $run->payslips()->forceDelete();

            $components = $this->componentsFor();
            $employees = $this->employeesFor($run);

            $counts = ['gross' => BigDecimal::zero(), 'deduction' => BigDecimal::zero(),
                'net' => BigDecimal::zero(), 'employer' => BigDecimal::zero()];
            $written = 0;

            foreach ($employees as $employee) {
                $payslip = $this->calculateOne($run, $employee, $components);
                $written++;

                if ($payslip->wasSkipped()) {
                    continue;
                }

                $counts['gross'] = $counts['gross']->plus($payslip->gross_amount);
                $counts['deduction'] = $counts['deduction']->plus($payslip->deduction_amount);
                $counts['net'] = $counts['net']->plus($payslip->net_amount);
                $counts['employer'] = $counts['employer']->plus($payslip->employer_cost_amount);
            }

            $run->forceFill([
                'gross_total' => (string) $counts['gross'],
                'deduction_total' => (string) $counts['deduction'],
                'net_total' => (string) $counts['net'],
                'employer_cost_total' => (string) $counts['employer'],
                'payslip_count' => $written,
                'calculated_at' => now(),
            ]);

            // BEFORE the save, not after it. `moveTo()` sets the attribute and the relation and
            // writes nothing itself — a status moved after the save is a status that only exists in
            // memory until the next `refresh()` throws it away, and the run stays in `draft` while
            // every page insists it was calculated. Its only visible consequence is that the run can
            // never be submitted, because `submit` is the one thing that reads the status back.
            $this->moveTo($run, 'calculated');
            $run->save();

            // The narrative counts the payslips and names no figure. An activity line is read by
            // anybody who can see the run, and the run is visible to people who may not see pay.
            $this->activity->record(
                $run,
                'calculated',
                $actor->name.' calculated '.$run->name.' — '.$written.' '.str('payslip')->plural($written).'.',
                ['payslips' => $written],
                $actor,
            );

            return $run->refresh();
        });
    }

    /**
     * One person's payslip, written out.
     *
     * The reasons a person cannot be paid are all the same shape — something the company has not
     * told us — so they all end in the same place: a payslip with a `skipped_reason` on the
     * register, where somebody can see it before pay day rather than after.
     */
    private function calculateOne(PayrollRun $run, Employee $employee, Collection $components): Payslip
    {
        $fraction = $this->periodFraction($run, $employee);

        $salary = EmployeeSalary::query()
            ->where('employee_id', $employee->getKey())
            ->inForceOn($run->period_end)
            ->orderByDesc('effective_from')
            ->first();

        $payslip = new Payslip([
            'payroll_run_id' => $run->getKey(),
            'employee_id' => $employee->getKey(),
            // Copied, not inferred at read time. `AccessScope::Own` resolves against columns on the
            // payslip itself and cannot reach the employee through the parent relation, so this is
            // the one thing that makes "my payslips" return anything. Copying it also freezes the
            // answer: if the personnel record is later relinked to a different login — a rehire
            // reusing an employee number, say — last year's payslips stay with the person who was
            // actually paid. Null where somebody has no account, which is common on site.
            'user_id' => $employee->user_id,
            // Derived from the run and the person rather than drawn from a sequence. A payslip is
            // not an independently numbered document — it exists only inside its run — and deriving
            // it means recalculating a run cannot burn a block of numbers each time. It is also
            // readable: PRL-2026-09-0001-MEI-0006 says which run and whose, with no lookup.
            'number' => $run->number.'-'.$employee->employee_no,
            'currency' => $run->currency,
            'period_fraction' => (string) $fraction,
        ]);

        if ($salary === null) {
            $payslip->skipped_reason = 'No salary is on record for this person for '.$run->periodLabel().'.';
            $payslip->save();

            return $payslip;
        }

        if ($salary->currency !== $run->currency) {
            // Not converted, deliberately. Guessing an exchange rate to pay somebody would be the
            // platform inventing a figure nobody agreed, and the right answer is a separate run in
            // that currency.
            $payslip->skipped_reason = 'Paid in '.$salary->currency.', which is not this run\'s currency.';
            $payslip->save();

            return $payslip;
        }

        $hours = $this->overtimeHours($run, $employee);
        $unpaidDays = $this->unpaidLeaveDays($run, $employee);

        $calculation = $this->calculator->calculate(
            salary: $salary,
            components: $components,
            assignments: $this->assignmentsFor($employee, $run->period_end),
            overtimeHours: (string) $hours,
            periodFraction: (string) $fraction,
            frequency: $run->frequency,
        );

        $payslip->fill($calculation->totals());
        $payslip->overtime_hours = (string) $hours;
        $payslip->unpaid_leave_days = (string) $unpaidDays;
        $payslip->save();

        // One insert for the whole payslip rather than one per line (§89). A hundred people with a
        // dozen components each is twelve hundred lines, and twelve hundred inserts is a payroll
        // run that times out on shared hosting.
        $now = now();
        $payslip->lines()->insert(array_map(fn (array $line) => [
            ...$line,
            'organization_id' => $run->organization_id,
            'payslip_id' => $payslip->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $calculation->lines));

        return $payslip;
    }

    // ------------------------------------------------------------------ inputs from elsewhere

    /**
     * Overtime that was approved AND actually worked.
     *
     * `worked_hours` rather than `hours`: the first is what the person recorded afterwards, the
     * second is what they were given permission to work. Paying the permission would pay for hours
     * nobody did, which is the whole reason the time module records both.
     */
    private function overtimeHours(PayrollRun $run, Employee $employee): BigDecimal
    {
        $hours = OvertimeRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$run->period_start, $run->period_end])
            ->statusKey('approved')
            ->whereNotNull('worked_hours')
            ->sum('worked_hours');

        return BigDecimal::of((string) ($hours ?: '0'));
    }

    /**
     * Days of leave in the period that the company does not pay for.
     *
     * Driven entirely by `leave_types.is_paid`, which is the company's own flag. The platform has no
     * opinion about whether compassionate leave is paid — that is a policy, and policies are rows.
     *
     * THE REQUEST'S OWN `days` IS NOT USED, because a fortnight of unpaid leave starting on the 28th
     * belongs to two months and the stored total belongs to neither. Each request is clipped to the
     * period and re-counted through the leave engine's own working-day calculator, so payroll and
     * leave agree about what a working day is — including public holidays — instead of payroll
     * holding a second, quietly different definition.
     */
    private function unpaidLeaveDays(PayrollRun $run, Employee $employee): BigDecimal
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->statusKey('approved')
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', false))
            ->whereDate('start_date', '<=', $run->period_end)
            ->whereDate('end_date', '>=', $run->period_start)
            ->get();

        $days = BigDecimal::zero();

        foreach ($requests as $request) {
            $from = $request->start_date->isBefore($run->period_start) ? $run->period_start : $request->start_date;
            $to = $request->end_date->isAfter($run->period_end) ? $run->period_end : $request->end_date;

            // A half day only counts as a half day in the period that actually contains it. Clipping
            // the other end away takes its half-day flag with it.
            $days = $days->plus($this->leave->countDays(
                $from,
                $to,
                $request->start_half_day && $from->isSameDay($request->start_date),
                $request->end_half_day && $to->isSameDay($request->end_date),
            ));
        }

        return $days;
    }

    /**
     * How much of the period the person was actually employed for.
     *
     * A starter on the 16th of a 30-day month is owed half a month, and a leaver likewise. Computed
     * from the personnel record's own dates rather than typed, and STORED on the payslip, because
     * `hired_on` can be corrected afterwards and the payslip must always explain the figure it
     * actually paid.
     */
    private function periodFraction(PayrollRun $run, Employee $employee): BigDecimal
    {
        $start = $run->period_start;
        $end = $run->period_end;
        $total = $start->diffInDays($end) + 1;

        $from = $employee->hired_on !== null && $employee->hired_on->isAfter($start) ? $employee->hired_on : $start;
        $to = $employee->exited_on !== null && $employee->exited_on->isBefore($end) ? $employee->exited_on : $end;

        if ($from->isAfter($to)) {
            return BigDecimal::zero();
        }

        $worked = $from->diffInDays($to) + 1;

        return BigDecimal::of($worked)->dividedBy($total, 6, RoundingMode::HALF_UP);
    }

    // ------------------------------------------------------------------ the people and the rules

    /**
     * Everybody who was employed for any part of the period.
     *
     * Not "everybody active today". A person who left on the 3rd is owed three days and would be
     * missed entirely by a live-employees query — which is exactly the kind of quiet omission that
     * only surfaces as an unpaid former employee with a grievance.
     */
    private function employeesFor(PayrollRun $run): Collection
    {
        return Employee::query()
            ->where(fn ($q) => $q->whereNull('hired_on')->orWhereDate('hired_on', '<=', $run->period_end))
            ->where(fn ($q) => $q->whereNull('exited_on')->orWhereDate('exited_on', '>=', $run->period_start))
            ->orderBy('employee_no')
            ->get();
    }

    /**
     * The pay structure, in the company's own calculation order, with the bands it needs (§89).
     *
     * Read once for the whole run rather than once per person. The structure cannot change halfway
     * through a calculation, and a hundred people would otherwise mean a hundred identical queries
     * plus a hundred more for the bands.
     */
    private function componentsFor(): Collection
    {
        return PayComponent::query()->active()->inCalculationOrder()->with('bands')->get();
    }

    /** This person's own exceptions, keyed by component so the calculator can look them up. */
    private function assignmentsFor(Employee $employee, Carbon $on): Collection
    {
        return EmployeePayComponent::query()
            ->where('employee_id', $employee->getKey())
            ->inForceOn($on)
            ->get()
            ->keyBy('pay_component_id');
    }

    // ------------------------------------------------------------------ routing

    public function submit(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->calculated_at === null) {
            throw new \DomainException('Calculate the run before sending it for approval — there is nothing to approve yet.');
        }

        $this->requireEditable($run, 'submitted');

        return DB::transaction(function () use ($run, $actor) {
            // The narrative says what is being approved and for whom, and NOT what it costs. It
            // reaches an approver's inbox, their email and possibly their phone, and a figure in a
            // notification is a figure outside the permission system (§68).
            $this->route(
                $run,
                $actor,
                $actor->name.' sent '.$run->name.' for '.$run->periodLabel().' for approval — '
                .$run->payslip_count.' '.str('payslip')->plural($run->payslip_count).'.',
            );

            return $run;
        });
    }

    /**
     * Approved. Freeze it.
     *
     * This is the only place `locked_at` is ever written, and nothing anywhere unsets it. From here
     * the run and every payslip in it are read-only for everybody including an administrator, which
     * is what makes a payslip usable as evidence months later.
     */
    protected function afterApproved(Model $record, WorkflowInstance $instance): void
    {
        /** @var PayrollRun $record */
        $record->forceFill(['locked_at' => now()])->save();
    }

    /**
     * Open a fresh run to correct one that is already locked.
     *
     * The correction carries a pointer back to what it corrects, and the original is left exactly
     * as approved. Editing the original would be easier for everybody in the moment and would
     * destroy the only record of what was actually paid (§59).
     */
    public function openCorrection(PayrollRun $original, array $attributes, User $actor): PayrollRun
    {
        if (! $original->isLocked()) {
            throw new \DomainException('That run has not been approved yet — change it directly rather than correcting it.');
        }

        return DB::transaction(function () use ($original, $attributes, $actor) {
            /** @var PayrollRun $run */
            // The period, frequency and currency come from the run being corrected and are written
            // AFTER the caller's attributes, so they cannot be overridden by anything posted. A
            // correction run that covers a different month, or pays in a different currency, is not
            // a correction of anything — it is a second payment wearing the first one's label.
            $run = $this->create([
                ...$attributes,
                'period_start' => $original->period_start,
                'period_end' => $original->period_end,
                'frequency' => $original->frequency,
                'currency' => $original->currency,
                'name' => $attributes['name'] ?? 'Correction to '.$original->name,
            ], $actor);

            $run->forceFill(['corrects_payroll_run_id' => $original->getKey()])->save();

            return $run;
        });
    }

    /**
     * Abandon a run that is not going to be paid.
     *
     * A period opened in error, or superseded before anybody was paid. The route is closed too, so
     * the approvers it was sent to stop being asked for a decision about something that no longer
     * exists — a stale approval sitting in an inbox is how somebody ends up approving a run that was
     * replaced a fortnight ago.
     */
    public function cancel(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        if ($run->isLocked()) {
            throw new \DomainException('An approved run cannot be cancelled. Open a correcting run instead.');
        }

        return DB::transaction(function () use ($run, $actor, $reason) {
            $this->closeRoute($run, $actor, $reason);
            $this->moveTo($run, 'cancelled');

            $run->forceFill([
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->activity->record(
                $run,
                'cancelled',
                $actor->name.' cancelled '.$run->number.': '.$reason,
                [],
                $actor,
            );

            return $run;
        });
    }

    // ------------------------------------------------------------------ guards

    /**
     * Refuse anything that would change a run that is no longer the author's to change.
     *
     * Two separate refusals on purpose. A locked run can never be touched again; a submitted one is
     * sitting in somebody's inbox, and recalculating it underneath them would mean the figures
     * approved were not the figures anybody reviewed.
     */
    private function requireEditable(PayrollRun $run, string $doing): void
    {
        if ($run->isLocked()) {
            throw new \DomainException('This run was approved on '.$run->locked_at->format('j M Y')
                .' and cannot be '.$doing.'. Open a correcting run instead.');
        }

        if (! $run->isEditable()) {
            throw new \DomainException('A run that is with its approvers cannot be '.$doing.'.');
        }
    }
}
