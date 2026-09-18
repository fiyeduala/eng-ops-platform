<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Promotions, transfers, secondments, acting appointments and regrades.
 *
 * APPLYING IS ITS OWN PERMISSION, separate from `manage`. A movement is the only thing in the
 * platform that writes somebody's position, department, site and reporting line, and the person who
 * raises paperwork is not automatically the person who may change the org chart. Applying early is
 * the specific risk `apply` guards: the route has approved a June transfer and somebody presses the
 * button in March.
 *
 * THERE IS NO APPROVE ABILITY. A movement is decided on its workflow route like everything else in
 * Phase 6. `apply` is what happens AFTER approval, and only then.
 *
 * ONCE APPLIED, NOTHING CHANGES. An applied movement is the history — the answer to "when did she
 * become a senior engineer" — and it stays editable to nobody, including an administrator. A mistake
 * is corrected by a new movement, which is also how it happened in the real world.
 */
class EmployeeMovementPolicy extends ScopedPolicy
{
    protected string $module = 'employee_movement';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof EmployeeMovement
            && $record->statusKey() === 'draft'
            && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof EmployeeMovement
            && $record->statusKey() === 'draft'
            && parent::delete($user, $record);
    }

    /** Send it down the route. Its author, or anybody who may edit it. */
    public function submit(User $user, EmployeeMovement $movement): bool
    {
        return $movement->statusKey() === 'draft' && $this->check($user, 'submit', $movement);
    }

    /**
     * Write the change onto the personnel record.
     *
     * The date is checked in the service too, not only here. A policy answers "may this person do
     * this"; "is it June yet" is a fact about the record, and the nightly sweep applies movements
     * with no user in the picture at all.
     */
    public function apply(User $user, EmployeeMovement $movement): bool
    {
        return $movement->statusKey() === 'approved'
            && $movement->applied_at === null
            && $movement->isDueToApply()
            && $this->check($user, 'apply', $movement);
    }

    public function cancel(User $user, EmployeeMovement $movement): bool
    {
        return ! in_array($movement->statusKey(), ['applied', 'rejected', 'cancelled'], true)
            && $this->check($user, 'manage', $movement);
    }
}
