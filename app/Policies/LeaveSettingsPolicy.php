<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Leave types, public holidays and balances — the company's leave rules (§47).
 *
 * Reading a type or a holiday needs only `leave.view`: everybody who books leave needs the names.
 * Balances are personal, so reading the register of them is HR's. Changing any of it is a
 * company-wide decision and needs `leave.manage` at ORGANIZATION reach — a department head who
 * holds `leave.manage` for their own department's requests must not be able to give their
 * department five extra days of annual leave.
 */
class LeaveSettingsPolicy extends ScopedPolicy
{
    protected string $module = 'leave';

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user);
    }

    public function view(User $user, Model $record): bool
    {
        if ($record instanceof LeaveBalance) {
            return $this->administers($user, $record);
        }

        return parent::view($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->administers($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->administers($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->administers($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->administers($user, $record);
    }

    /** Whether the user sets the company's leave rules, optionally for this row. */
    public function administers(User $user, ?Model $record = null): bool
    {
        return $this->check($user, 'manage', $record)
            && $this->resolver->hasOrganizationWide($user, 'leave.manage');
    }
}
