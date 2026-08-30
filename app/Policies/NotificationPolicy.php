<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Your bell, and nobody else's.
 *
 * Deliberately NOT a ScopedPolicy, and this is the one module in the system where that is the right
 * answer. ScopedPolicy exists to widen reach in controlled ways — up the org ladder, across a
 * project, through an explicit grant — and every one of those widenings would be wrong here. There
 * is no legitimate "as the department head, show me what my engineers have been notified about":
 * that is a surveillance feature wearing a management job title, and the notifications themselves
 * are only ever a restatement of records the manager can already open on their own authority.
 *
 * So there is no permission module for notifications, no scope, and no administrator override. The
 * rule is ownership and nothing else, which is why NotificationController needs no `permission:`
 * middleware — exactly like ProfileController, and for exactly the same reason.
 *
 * Bound to NotificationPreference as well: both tables carry `user_id` and both answer the same
 * question.
 */
class NotificationPolicy
{
    /** Everyone with a usable account has a bell of their own to look at. */
    public function viewAny(User $user): bool
    {
        return $user->canLogIn();
    }

    public function view(User $user, Model $record): bool
    {
        return $this->owns($user, $record);
    }

    /**
     * Notifications are written by NotificationDispatcher, never by a person — there is no
     * "send a notification" screen, because one would be a way to put arbitrary text in front of
     * somebody with the system's authority behind it.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /** Marking your own as read is the only update there is. */
    public function update(User $user, Model $record): bool
    {
        return $this->owns($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->owns($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    /**
     * Tenant first, then ownership.
     *
     * The tenant check is not redundant with the global scope. A record handed to a policy has
     * usually come through a scoped query, but "usually" is not a guarantee — a queued job, a
     * console command or a relation loaded without scope can all produce one — and a user id is
     * only unique within an installation, not within a company.
     */
    private function owns(User $user, Model $record): bool
    {
        return (int) $record->getAttribute('organization_id') === (int) $user->organization_id
            && (int) $record->getAttribute('user_id') === (int) $user->getKey();
    }
}
