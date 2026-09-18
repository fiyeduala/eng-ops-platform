<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeChecklist;
use App\Models\User;

/**
 * One person's joining or leaving list.
 *
 * TICKING IS `edit`, NOT `manage`. The people who actually do these things are IT, facilities and
 * the line manager — a list only HR can tick is a list HR fills in on everybody else's behalf,
 * which is precisely the fiction the module exists to stop.
 */
class EmployeeChecklistPolicy extends ScopedPolicy
{
    protected string $module = 'checklist';

    public function tick(User $user, EmployeeChecklist $checklist): bool
    {
        return $this->check($user, 'update', $checklist);
    }

    /** Undoing a tick, which rewrites what the record said was done. */
    public function untick(User $user, EmployeeChecklist $checklist): bool
    {
        return $this->check($user, 'manage', $checklist);
    }

    public function manage(User $user, EmployeeChecklist $checklist): bool
    {
        return $this->check($user, 'manage', $checklist);
    }
}
