<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What timesheets and overtime share on top of being routed: they belong to ONE PERSON.
 *
 * Everything about the route itself — submitting, the three outcomes, closing a stalled instance —
 * now lives in RoutedRecordService, which vacancies use too. What is left here is the half that is
 * genuinely about a person's own record: whose it is, and which department head can therefore read
 * it. Both are copied from the personnel file and neither is ever read from the form.
 */
abstract class RoutedTimeService extends RoutedRecordService
{
    /**
     * Whose record this is, copied from the personnel file and never from the form: these decide who
     * approves it and which department head can read it.
     */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        $employee = $record->getAttribute('employee_id') === null
            ? null
            : Employee::query()->find($record->getAttribute('employee_id'));

        if ($employee === null) {
            throw new \DomainException('There is no personnel record to put this '.$this->noun().' against.');
        }

        $record->setAttribute('requester_id', $employee->user_id);
        $record->setAttribute('org_unit_id', $employee->org_unit_id);
    }
}
