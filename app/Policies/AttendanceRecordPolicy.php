<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance.
 *
 * `create` is clocking yourself in and out — the controller never takes an employee id for that.
 * `update` is correcting somebody's times, which needs `attendance.edit` over that record and is
 * never available on your own shift: the person whose hours they are does not get to rewrite them.
 * Entering a forgotten shift for somebody else is `recordFor`, `attendance.edit` at any reach.
 */
class AttendanceRecordPolicy extends ScopedPolicy
{
    protected string $module = 'attendance';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof AttendanceRecord
            && (int) $record->user_id !== (int) $user->getKey()
            && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function recordFor(User $user): bool
    {
        return $this->check($user, 'edit');
    }
}
