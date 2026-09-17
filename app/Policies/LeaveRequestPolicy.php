<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may see, change, send and withdraw a leave request.
 *
 * THERE IS NO `approve` HERE. Deciding a request is acting on its workflow stage, and that is
 * WorkflowStageInstancePolicy's question — the same one it answers for a drawing. `leave.approve`
 * stays in the catalogue for administrators who want to name it in a route's role, and grants
 * nothing on its own.
 */
class LeaveRequestPolicy extends ScopedPolicy
{
    protected string $module = 'leave';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof LeaveRequest && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof LeaveRequest && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function submit(User $user, LeaveRequest $leave): bool
    {
        return $leave->statusKey() === 'draft' && $this->check($user, 'submit', $leave);
    }

    /**
     * The requester withdraws their own request; HR withdraws anybody's.
     *
     * "Their own" is the requester, not whoever typed it in — HR booking leave for somebody does
     * not give the person who typed it a standing right to take it away again.
     */
    public function cancel(User $user, LeaveRequest $leave): bool
    {
        // A draft is deleted, not cancelled: it never took any days or reached anybody's inbox.
        if (! in_array($leave->statusKey(), ['submitted', 'approved'], true)) {
            return false;
        }

        if ($this->manage($user, $leave)) {
            return true;
        }

        return (int) $leave->requester_id === (int) $user->getKey() && $this->check($user, 'submit', $leave);
    }

    public function manage(User $user, LeaveRequest $leave): bool
    {
        return $this->check($user, 'manage', $leave);
    }
}
