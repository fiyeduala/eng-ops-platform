<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Personnel records (§4).
 *
 * Two extra abilities exist because a personnel record is not one uniform blob of sensitivity:
 *
 *   employee.view            name, job title, unit, work email — the org chart
 *   employee.view_contact    personal phone, home address, next of kin
 *   employee.view_confidential  date of birth, national ID, salary band, disciplinary history
 *
 * A team lead needs the first for everyone they work with, the second rarely, the third almost
 * never. Collapsing them into one permission is how HR data leaks into a project directory.
 */
class EmployeePolicy extends ScopedPolicy
{
    protected string $module = 'employee';

    /** Personal contact details. Always readable on your own record. */
    public function viewContact(User $user, Employee $record): bool
    {
        if ($this->isSelf($user, $record)) {
            return true;
        }

        return $this->check($user, 'view_contact', $record);
    }

    /** Identity documents, pay band, disciplinary history. */
    public function viewConfidential(User $user, Employee $record): bool
    {
        if ($this->isSelf($user, $record)) {
            return true;
        }

        return $this->check($user, 'view_confidential', $record);
    }

    /**
     * Nobody edits their own personnel record.
     *
     * Not a permission question — a separation-of-duties one. Hire date, position, unit, salary
     * band and supervisor all determine what someone can reach and what they are paid; letting a
     * person change their own is a self-service privilege-escalation route no role should have.
     * Self-service edits to a name, phone or avatar go through the profile controller, which
     * writes a deliberately narrow set of columns.
     */
    public function update(User $user, $record): bool
    {
        if ($this->isSelf($user, $record)) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, $record): bool
    {
        if ($this->isSelf($user, $record)) {
            return false;
        }

        return parent::delete($user, $record);
    }

    private function isSelf(User $user, Employee $record): bool
    {
        return $record->user_id !== null && (int) $record->user_id === (int) $user->id;
    }
}
