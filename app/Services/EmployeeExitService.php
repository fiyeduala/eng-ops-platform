<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\PettyCashAccount;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Somebody leaving: what they still hold, and the moment their access stops.
 *
 * CLEARANCE IS ASKED OF THE REGISTERS, NOT OF THE LEAVER. `blockers()` queries the tables that
 * actually know — assets still in their custody, a petty cash tin still in their name — and those
 * answers do not depend on anybody remembering to tick a box. A checklist is somebody's word that a
 * thing was done; a blocker is a row that still exists. Only the second survives the person having
 * gone, and only the second can stop an exit being completed.
 *
 * COMPLETING AN EXIT SWITCHES THE SIGN-IN OFF. `employment_status`, `exited_on` and
 * `users.is_active` are written in ONE transaction, because the interesting failure is not that all
 * three are missed — it is that the first two happen and the third does not, and the company then
 * believes somebody has left while their password still works. `access_revoked_at` records the
 * moment, so "when did their access actually stop" has an answer.
 *
 * NOTHING HERE DELETES ANYTHING. A leaver's personnel record, their timesheets, their approvals and
 * their signature on last year's inspection all stay exactly where they are. An exit is a state, not
 * an erasure, and a system that removes people removes the evidence of what they did.
 */
class EmployeeExitService extends RegisterService
{
    /** Why somebody left. A starting list the company owns from the moment it edits it (§47, §73). */
    public const DEFAULT_REASONS = [
        'resignation', 'end_of_contract', 'retirement', 'dismissal', 'redundancy', 'death', 'other',
    ];

    /** Held only for the length of open(). See beforeCreate() below. */
    private ?Employee $leaving = null;

    protected function entityType(): string
    {
        return 'employee_exit';
    }

    protected function modelClass(): string
    {
        return EmployeeExit::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'employee_id', 'employee_checklist_id', 'confidentiality',
            'cleared_at', 'cleared_by', 'access_revoked_at',
        ];
    }

    /**
     * Open an exit for somebody, and start their leaving checklist.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function open(Employee $employee, array $attributes, User $actor): EmployeeExit
    {
        if ($employee->employment_status === 'exited') {
            throw new \DomainException($employee->fullName().' has already left.');
        }

        $existing = EmployeeExit::query()->forEmployee($employee->getKey())
            ->whereHas('status', fn ($q) => $q->whereIn('key', ['notified', 'clearing']))
            ->first();

        if ($existing !== null) {
            throw new \DomainException('There is already an open exit for '.$employee->fullName().' — '.$existing->number.'.');
        }

        $reason = trim((string) ($attributes['reason'] ?? ''));

        if ($reason === '') {
            throw new \DomainException('Say why they are leaving.');
        }

        if (blank($attributes['last_working_day'] ?? null)) {
            throw new \DomainException('Give their last working day.');
        }

        return DB::transaction(function () use ($employee, $attributes, $actor) {
            $this->leaving = $employee;

            try {
                /** @var EmployeeExit $exit */
                $exit = $this->create($attributes, $actor);
            } finally {
                $this->leaving = null;
            }

            $exit->setRelation('employee', $employee);

            // The leaving list, started from the company's own exit template. A company that has
            // not configured one still gets an exit — clearance is read from the registers, and the
            // checklist is the human half of it.
            $checklists = app(ChecklistService::class);
            $template = $checklists->defaultTemplate($employee->organization_id, 'exit');

            if ($template !== null) {
                $checklist = $checklists->start($employee, 'exit', $template, [
                    'started_on' => CarbonImmutable::today()->toDateString(),
                    'due_on' => $attributes['last_working_day'] ?? null,
                ], $actor);

                $exit->forceFill(['employee_checklist_id' => $checklist->getKey()]);
            }

            $exit->save();

            return $exit;
        });
    }

    /**
     * Who is leaving, stamped before the insert.
     *
     * It is NOT in the payload and never can be — `employee_id` is in the deny list above, so a
     * hidden field naming somebody else is dropped (§84). It comes from the record the controller
     * already authorised, and it is written here rather than afterwards because the column is NOT
     * NULL: an exit belonging to nobody is not a thing this register can hold, and a two-step write
     * would have to be able to hold one for the length of a transaction.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        if ($this->leaving === null) {
            throw new \DomainException('An exit is always opened for somebody.');
        }

        $record->setAttribute('employee_id', $this->leaving->getKey());
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var EmployeeExit $record */
        if ($record->isCompleted()) {
            throw new \DomainException('This exit is finished and cannot be changed.');
        }

        return parent::update($record, $attributes, $actor);
    }

    /**
     * What the leaver still holds, in the words of the registers that know.
     *
     * @return array<int,string>
     */
    public function blockers(Employee $employee): array
    {
        $blockers = [];

        // Custody is held by a USER — a person signs for a laptop, not a personnel file — so
        // somebody with no sign-in cannot be holding anything through this route.
        if ($employee->user_id !== null) {
            $assets = Asset::query()
                ->where('organization_id', $employee->organization_id)
                ->where('custodian_id', $employee->user_id)
                ->orderBy('name')
                ->get(['id', 'code', 'name']);

            foreach ($assets as $asset) {
                $blockers[] = 'Asset '.$asset->code.' ('.$asset->name.') is still in their custody.';
            }

            $tins = PettyCashAccount::query()
                ->where('organization_id', $employee->organization_id)
                ->where('custodian_id', $employee->user_id)
                ->where('is_active', true)
                ->get();

            foreach ($tins as $tin) {
                $blockers[] = BigDecimal::of($tin->balance ?? '0')->isZero()
                    ? 'They are still the custodian of petty cash account '.$tin->code.'.'
                    : 'Petty cash account '.$tin->code.' still holds '.$tin->balance.' in their name.';
            }
        }

        return $blockers;
    }

    /**
     * Say the leaver holds nothing outstanding.
     *
     * The registers are asked again HERE rather than trusting what the screen showed the person
     * pressing the button — a page rendered ten minutes ago is not evidence about now (§84).
     */
    public function clear(EmployeeExit $exit, User $actor): EmployeeExit
    {
        if ($exit->isCompleted()) {
            throw new \DomainException('This exit is already finished.');
        }

        $blockers = $this->blockers($exit->employee);

        if ($blockers !== []) {
            throw new \DomainException('They still hold: '.implode(' ', $blockers));
        }

        return DB::transaction(function () use ($exit, $actor) {
            $exit->forceFill(['cleared_at' => now(), 'cleared_by' => $actor->getKey()]);
            $this->moveTo($exit, 'clearing');
            $exit->updated_by = $actor->getKey();
            $exit->save();

            $this->activity->record($exit, 'cleared', $actor->name.' cleared '.$this->label($exit).'.', [], $actor);

            return $exit;
        });
    }

    /**
     * Finish the exit: end the employment and switch the sign-in off, together.
     *
     * The clearance is re-checked, the last working day must have arrived, and every write happens
     * in one transaction. See the class docblock for why the third write is the one that matters.
     */
    public function complete(EmployeeExit $exit, User $actor): EmployeeExit
    {
        if ($exit->isCompleted()) {
            throw new \DomainException('This exit is already finished.');
        }

        if ($exit->last_working_day->isAfter(CarbonImmutable::today())) {
            throw new \DomainException('They are still employed until '.$exit->last_working_day->format('j M Y').'.');
        }

        $blockers = $this->blockers($exit->employee);

        if ($blockers !== []) {
            throw new \DomainException('This exit cannot be finished. They still hold: '.implode(' ', $blockers));
        }

        return DB::transaction(function () use ($exit, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($exit->employee_id);

            $employee->forceFill([
                'employment_status' => 'exited',
                'exited_on' => $exit->last_working_day->toDateString(),
                'exit_reason' => $exit->reason,
                'is_active' => false,
            ])->save();

            // THE SIGN-IN. Deactivated, never deleted: the account is what every approval, upload
            // and audit entry they ever made points at, and removing it would orphan all of them.
            if ($employee->user_id !== null) {
                $user = User::query()->lockForUpdate()->find($employee->user_id);

                if ($user !== null) {
                    $user->forceFill(['is_active' => false])->save();
                }
            }

            $exit->forceFill([
                'access_revoked_at' => now(),
                'cleared_at' => $exit->cleared_at ?? now(),
                'cleared_by' => $exit->cleared_by ?? $actor->getKey(),
            ]);
            $this->moveTo($exit, 'completed');
            $exit->updated_by = $actor->getKey();
            $exit->save();

            $this->activity->record(
                $exit,
                'completed',
                $actor->name.' completed '.$this->label($exit).' and switched off the sign-in.',
                [],
                $actor,
            );

            return $exit;
        });
    }

    /** Called off — they are staying, or the date moved beyond recognition. */
    public function cancel(EmployeeExit $exit, string $reason, User $actor): EmployeeExit
    {
        if ($exit->isCompleted()) {
            throw new \DomainException('This exit is finished and cannot be withdrawn.');
        }

        if (blank($reason)) {
            throw new \DomainException('Say why the exit is being withdrawn.');
        }

        return DB::transaction(function () use ($exit, $reason, $actor) {
            $this->moveTo($exit, 'cancelled');
            $exit->updated_by = $actor->getKey();
            $exit->save();

            $this->activity->record($exit, 'cancelled', $actor->name.' withdrew '.$this->label($exit).'.', ['reason' => $reason], $actor);

            return $exit;
        });
    }
}
