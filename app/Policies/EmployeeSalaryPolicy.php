<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeSalary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may see and set what somebody is paid.
 *
 * SALARY IS THE MOST SENSITIVE FIELD IN THE PLATFORM. Not because it is secret in the legal sense,
 * but because of who is harmed by a leak: a line manager who learns that a peer earns more, a
 * colleague who learns it about a colleague. The rows are stamped `restricted`, which means gate 5
 * asks for unit reach or an explicit ACL row on top of everything else — and the access parent is
 * the employee, so a role at scope `own` reaches one salary history, the person's own.
 *
 * A SALARY ROW IS NEVER EDITED INTO A NEW FIGURE. A rise is a new row with a new `effective_from`,
 * and the previous row gets an `effective_to`. Editing the figure in place would silently rewrite
 * history: a payslip calculated in March against £40,000 would, after a July edit, appear to have
 * been calculated against £45,000, and there would be nothing anywhere to say otherwise. Editing is
 * therefore for correcting a typo made today, and closing is what happens when the pay changes.
 */
class EmployeeSalaryPolicy extends ScopedPolicy
{
    protected string $module = 'payroll';

    protected array $abilityMap = [
        'viewAny' => 'view_confidential',
        'view' => 'view_confidential',
        'create' => 'view_confidential',
        'update' => 'view_confidential',
        'delete' => 'view_confidential',
        'restore' => 'view_confidential',
        'forceDelete' => 'delete_permanently',
    ];

    /**
     * A salary that has already been paid against cannot be deleted.
     *
     * Deleting it would leave payslips that name a figure with no row behind them, and the first
     * person to notice would be somebody trying to answer a pay query from six months ago.
     */
    public function delete(User $user, Model $record): bool
    {
        if (! $record instanceof EmployeeSalary) {
            return false;
        }

        if ($record->hasBeenPaidAgainst()) {
            return false;
        }

        return parent::delete($user, $record);
    }
}
