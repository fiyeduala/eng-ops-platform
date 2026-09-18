<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;

/**
 * The visitor book.
 *
 * BOOKING AND ADMITTING ARE DIFFERENT PERMISSIONS. Anybody who can create a record can book a
 * visitor for next Tuesday — that is an engineer telling reception somebody is coming. Pressing the
 * gate button is `visitor.check_in`, and it belongs to the people who are actually at the desk. The
 * separation is the whole control on this register: there is no approval route here (see the model),
 * so the answer to "who may let somebody into the building" has to be a permission rather than an
 * inbox.
 *
 * A DEPARTED VISIT IS CLOSED FOR GOOD. It cannot be edited, deleted or checked in again. The record
 * is the answer to "who was on site when it happened", and a register whose history can be tidied
 * afterwards is worth nothing to the person asking (§59). Somebody who comes back after lunch is a
 * second visit, which is one button, not a reopened record.
 *
 * THE CHECKS ARE ORDERED STATE FIRST, PERMISSION SECOND, on purpose — a user with every permission
 * in the system still cannot check out somebody who never arrived, because that is not a matter of
 * authority. Both still run; the state test never grants anything on its own.
 */
class VisitorPolicy extends ScopedPolicy
{
    protected string $module = 'visitor';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Visitor
            && ! $record->hasDeparted()
            && parent::update($user, $record);
    }

    /**
     * Only a visit that never happened may be removed.
     *
     * Once somebody has come through the gate the record is evidence, and soft-deleting it would
     * take them off a roll call they belong on.
     */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Visitor
            && $record->checked_in_at === null
            && parent::delete($user, $record);
    }

    /** Let them in. */
    public function checkIn(User $user, Visitor $visitor): bool
    {
        return $visitor->checked_in_at === null
            && ! $visitor->hasDeparted()
            && $this->check($user, 'check_in', $visitor);
    }

    /** Sign them out again. */
    public function checkOut(User $user, Visitor $visitor): bool
    {
        return $visitor->isOnSite()
            && $this->check($user, 'check_out', $visitor);
    }

    /**
     * The fire roll call.
     *
     * Deliberately `view` and not `manage`: the point of this screen is that the person holding the
     * clipboard at the assembly point can open it. Requiring an administrative permission to find
     * out who is still in a burning building would be a security control that kills people.
     */
    public function rollCall(User $user): bool
    {
        return $this->check($user, 'view');
    }

    /** Cancel a booking, or record that nobody turned up. Neither is possible once they have. */
    public function cancel(User $user, Visitor $visitor): bool
    {
        return $visitor->checked_in_at === null
            && ! $visitor->hasDeparted()
            && $this->check($user, 'update', $visitor);
    }

    /**
     * Turn somebody away at the gate.
     *
     * Refusing entry is the gate's own decision and sits with the people who admit — it would be
     * absurd for a guard to be able to let somebody in but not to stop them.
     */
    public function refuse(User $user, Visitor $visitor): bool
    {
        return $visitor->checked_in_at === null
            && ! $visitor->hasDeparted()
            && $this->check($user, 'check_in', $visitor);
    }
}
