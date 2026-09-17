<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Assigned to me" is the record of somebody who reports to me.
 *
 * For time records — attendance, timesheets, overtime — the default route sends the record to the
 * person's line manager, and the approvals inbox drops any row whose subject the approver may not
 * open. So the baseline grants `.view` at `assigned`, and this is what `assigned` means here: rows
 * whose `employee_id` is a direct report of the viewer. Not the whole reporting chain — a director
 * who needs the department uses a department-scoped grant.
 *
 * Scopeable already defines applyAssignedScope(), so a model calls this from its own override rather
 * than the trait taking the name — two traits with one method is a fatal error.
 *
 * @mixin Model
 */
trait SeenByLineManager
{
    /** @param  Builder<static>  $query */
    protected function applyLineManagerScope(Builder $query, User $user): void
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
}
