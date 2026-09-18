<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Concerns\GrantsRecordAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Promotions, transfers, secondments, acting appointments and regrades — asked for, routed, applied.
 *
 * A MOVEMENT IS THE HISTORY. Editing `employees.position_id` in place answers "where is this person
 * now" and destroys "how did they get there", which is the question every reference letter, every
 * grievance and every long-service calculation actually asks. Nothing else in the platform writes
 * those four columns on an employee; this register does, once, when a movement is applied.
 *
 * APPROVAL AND APPLICATION ARE DIFFERENT DAYS. A transfer approved in March to take effect in June
 * must not move anybody in March — their manager, their leave approver and their timesheet route
 * would all change three months early. So approval only applies the change when the effective date
 * has arrived; otherwise the movement waits at `approved` and the daily sweep applies it on the
 * morning it is due.
 *
 * THE BEFORE SIDE IS A SNAPSHOT TAKEN AT SUBMISSION, not a join at read time. Reading "from" off the
 * employee record would make every historical movement rewrite itself the moment the next one was
 * applied, and a history that changes when you read it is not a history.
 *
 * ENDING IS NOT REVERTING. A secondment with an end date says when it stops, and the platform
 * reports it — but the return is its own movement, because somebody coming back from six months in
 * Port Harcourt frequently does not come back to the same desk, and guessing that they do would
 * quietly write a job title nobody approved.
 *
 * PAY IS NOT HERE. A promotion changes a salary, and salaries live in payroll (part 5) under their
 * own permission. A movement that carried a pay figure would put every salary in the company inside
 * a register department heads can read.
 */
class EmployeeMovementService extends RoutedRecordService
{
    use GrantsRecordAccess;

    protected function entityType(): string
    {
        return 'employee_movement';
    }

    protected function modelClass(): string
    {
        return EmployeeMovement::class;
    }

    protected function noun(): string
    {
        return 'movement';
    }

    protected function protectedAttributes(): array
    {
        return [
            ...parent::protectedAttributes(),
            // The before side is stamped at submission, and the application columns by the act of
            // applying. Neither is ever a form field (§84).
            'from_position_id', 'from_org_unit_id', 'from_location_id', 'from_supervisor_id',
            'applied_at', 'applied_by',
        ];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        parent::beforeCreate($record, $attributes, $actor);

        /** @var EmployeeMovement $record */
        $record->requester_id = $actor->getKey();
        $this->stampFrom($record);
        $this->checkShape($record);
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var EmployeeMovement $record */
        return $actor->name.' drafted a '.strtolower($record->kindLabel()).' for '.($record->employee?->fullName() ?? 'an employee').'.';
    }

    /** @param array<string,mixed> $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var EmployeeMovement $record */
        $this->requireDraft($record, 'changed');

        // Who is moving is settled when the movement is raised. Changing it would carry another
        // person's approvals, and the "from" snapshot would belong to somebody else entirely.
        unset($attributes['employee_id']);

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->stampFrom($record);
            $this->checkShape($record);

            return parent::update($record, [], $actor);
        });
    }

    public function submit(EmployeeMovement $movement, User $actor): EmployeeMovement
    {
        $this->requireDraft($movement, 'submitted');

        return DB::transaction(function () use ($movement, $actor) {
            $movement->loadMissing('employee');
            $this->stampFrom($movement);
            $this->checkShape($movement);
            $this->refuseOverlap($movement);

            $this->route(
                $movement,
                $actor,
                $actor->name.' asked for a '.strtolower($movement->kindLabel()).' for '
                .($movement->employee?->fullName() ?? 'an employee').' effective '
                .$movement->effective_on->format('j M Y').'.',
            );

            return $movement;
        });
    }

    /**
     * Approved. Apply it now if the date has come, otherwise leave it for the sweep.
     */
    protected function afterApproved(Model $record, WorkflowInstance $instance): void
    {
        /** @var EmployeeMovement $record */
        if ($record->isDueToApply()) {
            $this->apply($record, null);
        }
    }

    /**
     * Write the change onto the employee record.
     *
     * The employee row is locked for the duration, because two movements for the same person
     * approved in the same minute — a transfer and a promotion, which is a normal pairing — would
     * otherwise each read the pre-change employee and the second would undo the first's columns.
     *
     * `$actor` is null when the workflow or the nightly sweep applies it: nobody pressed anything,
     * and recording the last approver as the person who did it would be a false audit entry (§59).
     */
    public function apply(EmployeeMovement $movement, ?User $actor): EmployeeMovement
    {
        if ($movement->statusKey() !== 'approved') {
            throw new \DomainException('Only an approved movement can be applied.');
        }

        if ($movement->applied_at !== null) {
            throw new \DomainException('This movement has already been applied.');
        }

        if (! $movement->isDueToApply()) {
            throw new \DomainException('This movement takes effect on '.$movement->effective_on->format('j M Y').'.');
        }

        return DB::transaction(function () use ($movement, $actor) {
            /** @var Employee|null $employee */
            $employee = Employee::query()->lockForUpdate()->find($movement->employee_id);

            if ($employee === null) {
                throw new \DomainException('The personnel record this movement belongs to no longer exists.');
            }

            // Only the columns the movement actually changes. A transfer that named a department and
            // no position must not blank the person's job title.
            $changes = array_filter([
                'position_id' => $movement->to_position_id,
                'org_unit_id' => $movement->to_org_unit_id,
                'location_id' => $movement->to_location_id,
                'supervisor_id' => $movement->to_supervisor_id,
            ], fn ($value) => $value !== null);

            if ($changes !== []) {
                $employee->forceFill($changes);
                $employee->save();
            }

            $movement->forceFill([
                'applied_at' => now(),
                'applied_by' => $actor?->getKey(),
            ]);
            $this->moveTo($movement, 'applied');
            $movement->save();

            // `changeSummary()` returns one line per column that actually moved, which is what the
            // record page lists. The timeline wants a sentence, so they are joined here rather than
            // the model having a second, nearly identical method for the same facts.
            $summary = implode('; ', $movement->changeSummary()) ?: $movement->kindLabel();

            $narrative = ($actor?->name ?? 'The system').' applied '.$movement->number.': '.$summary.'.';

            $this->activity->record($movement, 'applied', $narrative, ['employee' => $employee->getKey()], $actor);

            // The person it happened to may now read the paperwork.
            //
            // Not before this moment, and the distinction is the whole point. A drafted or even an
            // approved movement is HR still arranging something — "we are thinking of moving him to
            // Projects" is not the employee's to read, and `own` scope on this register means the
            // movements somebody RAISED, not the ones raised about them. Once it is applied the
            // change is on their personnel record and they are plainly entitled to the document that
            // did it. A Gate 4 row is the honest way to say exactly that and nothing wider (§68).
            $this->grantSubjectAccess($movement, $employee, $actor);
            // And on the personnel record too, so somebody reading a person's timeline sees the move
            // without having to know this register exists.
            $this->activity->record($employee, 'updated', $narrative, ['movement' => $movement->getKey()], $actor, null, $employee->org_unit_id);

            return $movement;
        });
    }

    /**
     * Every approved movement whose day has come. The daily sweep's whole query.
     *
     * @return Collection<int,EmployeeMovement>
     */
    public function dueToApply(): Collection
    {
        return EmployeeMovement::query()
            ->whereNull('applied_at')
            ->whereDate('effective_on', '<=', CarbonImmutable::today()->toDateString())
            ->whereHas('status', fn ($q) => $q->where('key', 'approved'))
            ->with(['employee', 'status'])
            ->get();
    }

    public function cancel(EmployeeMovement $movement, string $reason, User $actor): EmployeeMovement
    {
        if (in_array($movement->statusKey(), ['applied', 'rejected', 'cancelled'], true)) {
            throw new \DomainException('This movement has already finished.');
        }

        return DB::transaction(function () use ($movement, $reason, $actor) {
            $this->closeRoute($movement, $actor, $reason);

            $movement->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ]);
            $this->moveTo($movement, 'cancelled');
            $movement->save();

            $this->activity->record($movement, 'cancelled', $actor->name.' cancelled '.$movement->number.': '.$reason, [], $actor);

            return $movement;
        });
    }

    // ================================================================== internals

    /**
     * `view` and nothing else: reading the movement that moved you is not being able to cancel it,
     * edit it, or reach the next one. An employee with no account gets nothing, which is not a
     * failure — plenty of people on a personnel register have never signed in.
     */
    private function grantSubjectAccess(EmployeeMovement $movement, Employee $employee, ?User $actor): void
    {
        $subject = $employee->user_id === null ? null : User::query()->find($employee->user_id);

        if ($subject === null) {
            return;
        }

        // The stored ability is the permission key the gate checks against, not the policy verb.
        $this->grantRecordAccess($movement, $subject, $actor, 'The person this movement applied to.', 'employee_movement.view');
    }

    /** The before side, read off the employee while the movement is still a draft. */
    private function stampFrom(EmployeeMovement $movement): void
    {
        $employee = $movement->relationLoaded('employee')
            ? $movement->employee
            : Employee::query()->find($movement->employee_id);

        if ($employee === null) {
            return;
        }

        $movement->forceFill([
            'from_position_id' => $employee->position_id,
            'from_org_unit_id' => $employee->org_unit_id,
            'from_location_id' => $employee->location_id,
            'from_supervisor_id' => $employee->supervisor_id,
        ]);
    }

    private function checkShape(EmployeeMovement $movement): void
    {
        if ($movement->employee_id === null) {
            throw new \DomainException('Say who is moving.');
        }

        if (blank($movement->kind)) {
            throw new \DomainException('Say what kind of movement this is.');
        }

        if ($movement->effective_on === null) {
            throw new \DomainException('Give the date this takes effect.');
        }

        if ($movement->ends_on !== null && $movement->ends_on->lt($movement->effective_on)) {
            throw new \DomainException('The movement ends before it starts.');
        }

        // A movement that changes nothing is a form somebody filled in by accident, and approving it
        // would put an entry in a person's history saying they moved from a job to the same job.
        $unchanged = ($movement->to_position_id === null || $movement->to_position_id === $movement->from_position_id)
            && ($movement->to_org_unit_id === null || $movement->to_org_unit_id === $movement->from_org_unit_id)
            && ($movement->to_location_id === null || $movement->to_location_id === $movement->from_location_id)
            && ($movement->to_supervisor_id === null || $movement->to_supervisor_id === $movement->from_supervisor_id);

        if ($unchanged) {
            throw new \DomainException('Nothing changes on this movement. Choose the new position, department, location or supervisor.');
        }

        // Nobody reports to themselves, and a chain that loops breaks every approval route that
        // walks it — including the one this very movement is about to travel down.
        if ($movement->to_supervisor_id !== null && $movement->to_supervisor_id === $movement->employee_id) {
            throw new \DomainException('Somebody cannot report to themselves.');
        }
    }

    /**
     * One movement at a time per person per effective date.
     *
     * Two approved movements for the same person on the same day apply in whichever order the sweep
     * happens to read them, and the loser's columns are silently overwritten. Refusing at submission
     * is the only point where a person is still there to be told which one they meant.
     */
    private function refuseOverlap(EmployeeMovement $movement): void
    {
        $clash = EmployeeMovement::query()
            ->where('employee_id', $movement->employee_id)
            ->whereKeyNot($movement->getKey())
            ->whereDate('effective_on', $movement->effective_on->toDateString())
            ->whereHas('status', fn ($q) => $q->whereIn('key', ['submitted', 'approved']))
            ->first();

        if ($clash !== null) {
            throw new \DomainException('There is already a movement for this person on that date ('.$clash->number.').');
        }
    }
}
