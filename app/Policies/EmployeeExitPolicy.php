<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeExit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody leaving.
 *
 * `clear` AND `complete` ARE SEPARATE PERMISSIONS. Saying a leaver holds nothing outstanding is a
 * clerical check; ending the employment and switching the sign-in off is not, and the person who
 * does the first every week is not necessarily trusted with the second. The service re-runs the
 * clearance regardless, so neither permission can be used to skip it.
 *
 * A COMPLETED EXIT IS A RECORD. It cannot be edited or deleted by anybody: it is what the company
 * will point at when asked when somebody's access actually stopped.
 */
class EmployeeExitPolicy extends ScopedPolicy
{
    protected string $module = 'employee_exit';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof EmployeeExit && ! $record->isCompleted() && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof EmployeeExit && ! $record->isCompleted() && parent::delete($user, $record);
    }

    public function clear(User $user, EmployeeExit $exit): bool
    {
        return ! $exit->isCompleted() && $this->check($user, 'clear', $exit);
    }

    public function complete(User $user, EmployeeExit $exit): bool
    {
        return ! $exit->isCompleted() && $this->check($user, 'complete', $exit);
    }

    public function cancel(User $user, EmployeeExit $exit): bool
    {
        return ! $exit->isCompleted() && $this->check($user, 'manage', $exit);
    }

    /**
     * The exit interview.
     *
     * Often the only honest feedback a company ever gets, and emphatically not for the leaver's own
     * line manager to read — which is what `view_confidential` is for here.
     */
    public function viewInterview(User $user, EmployeeExit $exit): bool
    {
        return $this->check($user, 'view_confidential', $exit);
    }

    public function manage(User $user, EmployeeExit $exit): bool
    {
        return $this->check($user, 'manage', $exit);
    }
}
