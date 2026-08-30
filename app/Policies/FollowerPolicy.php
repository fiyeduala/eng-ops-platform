<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Follower;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Following a record.
 *
 * The rule is one line: you may follow what you may already see, and you may only unfollow on
 * your own behalf. Following grants nothing — see the Follower model — so the risk here is not
 * escalation, it is the two smaller holes either side of it:
 *
 *   - Subscribing to a record you cannot see. Harmless in itself, but the notification that
 *     followed would carry the record's number and title to somebody with no right to them, and
 *     §68 says a notification must never do that. Checking `view` on the followable at subscribe
 *     time is the cheap half; the notification layer re-checking each recipient at send time is
 *     the half that survives a later loss of reach.
 *   - Subscribing SOMEBODY ELSE. There is no legitimate "make Sam follow this" in V1 — the system
 *     adds assignees and creators itself, with `reason` recording that it did — so a follower row
 *     may only ever name the actor.
 *
 * The followable is resolved through the Gate, so each module's own policy answers for its own
 * records and this class never learns what a task or a document is.
 */
class FollowerPolicy extends ScopedPolicy
{
    protected string $module = 'follower';

    public function viewAny(User $user): bool
    {
        return $user->canLogIn();
    }

    /**
     * Your own subscriptions are yours to read. Someone else's are not — who is watching a record
     * is itself information about that record.
     */
    public function view(User $user, Model $record): bool
    {
        return $record instanceof Follower && (int) $record->user_id === (int) $user->id;
    }

    /**
     * Class-level: everyone with a usable account may follow SOMETHING. The question that matters
     * is `follow`, below, which has the record in hand.
     */
    public function create(User $user): bool
    {
        return $user->canLogIn();
    }

    /** Follow this specific record: reach over it, decided by the record's own policy. */
    public function follow(User $user, Model $followable): bool
    {
        return $user->canLogIn()
            && (int) $followable->getAttribute('organization_id') === (int) $user->organization_id
            && Gate::forUser($user)->allows('view', $followable);
    }

    /**
     * Unfollowing is always allowed on your own row, even after you have lost sight of the record.
     * Refusing it would leave people permanently subscribed to things they can no longer open.
     */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Follower && (int) $record->user_id === (int) $user->id;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
