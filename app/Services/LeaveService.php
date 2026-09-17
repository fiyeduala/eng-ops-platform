<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkflowInstance;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests: counted, checked, routed, decided, withdrawn.
 *
 * THE DAY COUNT IS NEVER TAKEN FROM THE FORM. It is worked out here from the dates, the company's
 * working week (`work.working_days`) and its public holidays, every time the dates change (§84).
 *
 * APPROVAL IS NOT THIS SERVICE'S DECISION. `submit()` hands the request to WorkflowEngine and the
 * engine calls back through LeaveRequest's hooks when the route finishes. There is no `approve()`
 * method here, deliberately: one would be a second way to approve leave that no workflow governs.
 *
 * DAYS TAKEN ARE COMPUTED, NOT STORED. A balance holds what was granted; "used" is the sum of the
 * submitted and approved requests for that type and year. Withdrawing a request therefore gives the
 * days back without anything having to remember to.
 */
class LeaveService extends RegisterService
{
    /** Statuses whose days count against a balance and block an overlapping request. */
    public const COMMITTED = ['submitted', 'approved'];

    /** Longest single request, as a guard on the day-by-day count. A year crossing is refused anyway. */
    private const MAX_SPAN_DAYS = 366;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly WorkflowEngine $workflow,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'leave_request';
    }

    protected function modelClass(): string
    {
        return LeaveRequest::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'days', 'requester_id', 'org_unit_id', 'project_id', 'confidentiality',
            'submitted_at', 'decided_at', 'decision_reason',
            'cancelled_by', 'cancelled_at', 'cancellation_reason',
        ];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var LeaveRequest $record */
        $employee = $record->employee_id === null ? null : Employee::query()->find($record->employee_id);

        if ($employee === null) {
            throw new \DomainException('There is no personnel record to book this leave against.');
        }

        // Copied from the personnel record, never from the form: these decide who approves the
        // request and which department head can read it.
        $record->requester_id = $employee->user_id;
        $record->org_unit_id = $employee->org_unit_id;

        $this->applyDays($record);
    }

    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var LeaveRequest $record */
        if ($record->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft leave request can be changed.');
        }

        // Whose leave it is was settled when the request was opened.
        unset($attributes['employee_id']);

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->applyDays($record);

            return parent::update($record, [], $actor);
        });
    }

    // ================================================================== submitting

    /**
     * Send the request for approval — or straight to approved, for a type the company does not approve.
     *
     * The rules are checked under a lock on the employee's row, so two requests submitted at once
     * cannot each see the same remaining balance and both be accepted.
     */
    public function submit(LeaveRequest $leave, User $actor): LeaveRequest
    {
        if ($leave->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft leave request can be submitted.');
        }

        return DB::transaction(function () use ($leave, $actor) {
            Employee::query()->lockForUpdate()->findOrFail($leave->employee_id);

            $leave->loadMissing('leaveType', 'employee');
            $this->applyDays($leave);
            $this->assertRules($leave);

            $leave->forceFill(['submitted_at' => now(), 'decided_at' => null, 'decision_reason' => null]);

            if (! $leave->leaveType->requires_approval) {
                $leave->decided_at = now();
                $this->moveTo($leave, 'approved');
                $leave->save();

                $this->activity->record(
                    $leave,
                    'approved',
                    $actor->name.' booked '.$this->label($leave).'. '.$leave->leaveType->name.' does not need approval.',
                    ['days' => (string) $leave->days],
                    $actor,
                );

                return $leave;
            }

            // Moved BEFORE the route starts, because the route can finish inside `resubmit()` — a
            // workflow whose every stage is skipped closes immediately and calls markApproved(),
            // which must not then be overwritten by a "submitted" written afterwards.
            $this->moveTo($leave, 'submitted');
            $leave->save();

            $this->activity->record(
                $leave,
                'submitted',
                $actor->name.' submitted '.$this->label($leave).' ('.$leave->days.' days).',
                ['days' => (string) $leave->days],
                $actor,
            );

            // `resubmit` keeps the conversation of a request that was sent back for changes, and
            // starts a fresh route for one that has never been anywhere.
            if ($this->workflow->resubmit($leave, $actor) === null) {
                throw new \DomainException(
                    'No approval route is configured for leave requests, so this cannot be sent anywhere. Ask an administrator to publish one.'
                );
            }

            return $leave;
        });
    }

    // ================================================================== workflow outcomes

    /** Called by the workflow engine, inside its closing transaction. */
    public function markApproved(LeaveRequest $leave, WorkflowInstance $instance): void
    {
        $this->decide($leave, $instance, 'approved', 'approved');
    }

    public function markRejected(LeaveRequest $leave, WorkflowInstance $instance): void
    {
        $this->decide($leave, $instance, 'rejected', 'rejected');
    }

    /** Sent back for changes: the request becomes a draft its owner can edit and send again. */
    public function markReturned(LeaveRequest $leave, WorkflowInstance $instance): void
    {
        $leave->forceFill(['submitted_at' => null]);
        $this->decide($leave, $instance, 'draft', 'returned');
    }

    private function decide(LeaveRequest $leave, WorkflowInstance $instance, string $to, string $verb): void
    {
        $leave->forceFill([
            'decided_at' => $to === 'draft' ? null : now(),
            'decision_reason' => $instance->outcome_reason,
        ]);
        $this->moveTo($leave, $to);
        $leave->save();

        $this->activity->record(
            $leave,
            $verb,
            $this->label($leave).' was '.$verb.' through its approval route.',
            array_filter(['workflow_instance' => $instance->getKey()]),
        );
    }

    // ================================================================== withdrawing

    /**
     * Withdraw a request that is waiting or approved.
     *
     * Leave that has already been taken is history, not a plan, and cancelling it would quietly hand
     * back days somebody spent — so an approved request that has started can only be corrected by
     * somebody holding `leave.manage`, and one that has ended not at all.
     */
    public function cancel(LeaveRequest $leave, User $actor, string $reason, bool $asManager = false): LeaveRequest
    {
        if (! in_array($leave->statusKey(), ['draft', ...self::COMMITTED], true)) {
            throw new \DomainException('This leave request is already closed.');
        }

        if (blank($reason)) {
            throw new \DomainException('Say why the leave is being cancelled.');
        }

        $today = CarbonImmutable::today();

        if ($leave->statusKey() === 'approved') {
            if ($leave->end_date->lt($today)) {
                throw new \DomainException('This leave has already been taken, so it cannot be cancelled.');
            }

            if (! $asManager && $leave->start_date->lte($today)) {
                throw new \DomainException('This leave has already started. Ask HR to cut it short.');
            }
        }

        return DB::transaction(function () use ($leave, $actor, $reason) {
            $leave->forceFill([
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
            $this->moveTo($leave, 'cancelled');
            $leave->save();

            // A stalled route is still open — nobody could be found to decide it — and must be
            // closed too, or it would sit in an administrator's "stuck approvals" list forever.
            $instance = $leave->workflowInstances()
                ->whereIn('status', [WorkflowInstance::STATUS_RUNNING, WorkflowInstance::STATUS_STALLED])
                ->first();

            if ($instance !== null) {
                $this->workflow->cancel($instance, $actor, $reason);
            }

            $this->activity->record(
                $leave,
                'cancelled',
                $actor->name.' cancelled '.$this->label($leave).'.',
                ['reason' => $reason],
                $actor,
            );

            return $leave;
        });
    }

    // ================================================================== counting

    /**
     * Working days between two dates, inclusive, as the company defines a working day.
     *
     * @return string a decimal with two places
     */
    public function countDays(
        CarbonInterface $start,
        CarbonInterface $end,
        bool $startHalf = false,
        bool $endHalf = false,
    ): string {
        $start = CarbonImmutable::parse($start->toDateString());
        $end = CarbonImmutable::parse($end->toDateString());

        $workingDays = array_map('strtolower', $this->settings->array(
            'work.working_days',
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ));

        $holidays = PublicHoliday::query()
            ->where(fn ($q) => $q->where('is_recurring', true)
                ->orWhereBetween('holiday_date', [$start->toDateString(), $end->toDateString()]))
            ->get();

        $total = BigDecimal::zero();

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (! in_array(strtolower($day->englishDayOfWeek), $workingDays, true)) {
                continue;
            }

            if ($holidays->contains(fn (PublicHoliday $holiday) => $holiday->fallsOn($day))) {
                continue;
            }

            $portion = BigDecimal::one();

            if ($startHalf && $day->isSameDay($start)) {
                $portion = BigDecimal::of('0.5');
            }

            if ($endHalf && $day->isSameDay($end)) {
                $portion = BigDecimal::of('0.5');
            }

            $total = $total->plus($portion);
        }

        return (string) $total->toScale(2);
    }

    /**
     * The balance for one person, type and year — created on first use.
     *
     * Opening a year grants the type's `days_per_year` and carries over whatever was left of the
     * previous year, up to the type's limit. Returns null for a type that is not counted.
     */
    public function balanceFor(Employee $employee, LeaveType $type, int $year, bool $persist = true): ?LeaveBalance
    {
        if (! $type->tracksBalance()) {
            return null;
        }

        $existing = LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('year', $year)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $carried = BigDecimal::zero();
        $previous = LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->where('year', $year - 1)
            ->first();

        if ($previous !== null) {
            $left = BigDecimal::of($previous->granted())->minus($this->daysUsed($employee, $type, $year - 1));
            $limit = BigDecimal::of($type->carry_over_limit ?? '0');
            $carried = $left->isNegative() ? BigDecimal::zero() : ($left->isGreaterThan($limit) ? $limit : $left);
        }

        $balance = new LeaveBalance([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'year' => $year,
            'entitled' => (string) $type->days_per_year,
            'carried_over' => (string) $carried->toScale(2),
            'adjustment' => '0',
        ]);
        $balance->organization_id = $employee->organization_id;
        $balance->setRelation('leaveType', $type);

        if ($persist) {
            $balance->save();
        }

        return $balance;
    }

    /** Days of this type committed in this year — submitted or approved. */
    public function daysUsed(Employee $employee, LeaveType $type, int $year, ?int $exceptRequestId = null): string
    {
        $sum = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->whereYear('start_date', $year)
            ->whereIn('status_id', $this->committedStatusIds())
            ->when($exceptRequestId !== null, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->sum('days');

        return (string) BigDecimal::of((string) $sum)->toScale(2);
    }

    /** @return array<int,int> */
    public function committedStatusIds(): array
    {
        return $this->statuses->statusesFor('leave_request')
            ->whereIn('key', self::COMMITTED)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    // ================================================================== rules

    /** Work out `days` from the dates, refusing shapes that do not make sense. */
    private function applyDays(LeaveRequest $leave): void
    {
        $leave->loadMissing('leaveType');
        $type = $leave->leaveType ?? LeaveType::query()->find($leave->leave_type_id);

        if ($type === null) {
            throw new \DomainException('Choose a leave type.');
        }

        if ($leave->start_date === null || $leave->end_date === null) {
            throw new \DomainException('Give the first and last day of the leave.');
        }

        if ($leave->end_date->lt($leave->start_date)) {
            throw new \DomainException('The leave cannot end before it starts.');
        }

        if ($leave->start_date->year !== $leave->end_date->year) {
            throw new \DomainException('Leave is counted per calendar year. Split a request that crosses 31 December into two.');
        }

        if ($leave->start_date->diffInDays($leave->end_date) >= self::MAX_SPAN_DAYS) {
            throw new \DomainException('That is longer than a year.');
        }

        $halves = $leave->start_half_day || $leave->end_half_day;

        if ($halves && ! $type->allow_half_days) {
            throw new \DomainException($type->name.' is taken in whole days.');
        }

        if ($leave->start_date->isSameDay($leave->end_date) && $leave->start_half_day && $leave->end_half_day) {
            throw new \DomainException('For a single half day, tick one half only.');
        }

        $leave->days = $this->countDays($leave->start_date, $leave->end_date, (bool) $leave->start_half_day, (bool) $leave->end_half_day);

        if (! BigDecimal::of($leave->days)->isPositive()) {
            throw new \DomainException('There are no working days in that period.');
        }
    }

    private function assertRules(LeaveRequest $leave): void
    {
        $type = $leave->leaveType;

        if (! $type->is_active || $type->trashed()) {
            throw new \DomainException($type->name.' is no longer offered.');
        }

        if ($type->max_consecutive_days !== null && BigDecimal::of($leave->days)->isGreaterThan($type->max_consecutive_days)) {
            throw new \DomainException($type->name.' can be taken for at most '.$type->max_consecutive_days.' days at a time.');
        }

        if ($type->min_notice_days > 0 && $leave->start_date->lt(CarbonImmutable::today()->addDays($type->min_notice_days))) {
            throw new \DomainException($type->name.' needs '.$type->min_notice_days.' days\' notice.');
        }

        $overlap = LeaveRequest::query()
            ->where('employee_id', $leave->employee_id)
            ->whereKeyNot($leave->getKey())
            ->whereIn('status_id', $this->committedStatusIds())
            ->whereDate('start_date', '<=', $leave->end_date->toDateString())
            ->whereDate('end_date', '>=', $leave->start_date->toDateString())
            ->first();

        if ($overlap !== null) {
            throw new \DomainException('This overlaps '.$overlap->number.', which is already booked or awaiting approval.');
        }

        $balance = $this->balanceFor($leave->employee, $type, $leave->start_date->year);

        if ($balance !== null) {
            $available = BigDecimal::of($balance->granted())
                ->minus($this->daysUsed($leave->employee, $type, $leave->start_date->year, $leave->getKey()));

            if (BigDecimal::of($leave->days)->isGreaterThan($available)) {
                throw new \DomainException(
                    'Not enough '.$type->name.' left: '.$leave->days.' days requested, '.$available->toScale(2).' available.'
                );
            }
        }
    }
}
