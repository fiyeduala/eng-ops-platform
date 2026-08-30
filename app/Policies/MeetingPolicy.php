<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Meetings, minutes and decisions (§19).
 *
 * The five gates are inherited; the meeting's own reach is organizer ∪ participants ∪ org ladder ∪
 * project team, all compiled by the engine from the model's hooks.
 *
 * The separations that matter here are between three acts that a single `edit` permission would
 * collapse into one:
 *
 *   ARRANGING the meeting (`meeting.edit` / `meeting.manage`) — time, place, agenda, who comes.
 *   RECORDING minutes (`meeting.record_minutes`) — secretarial work, often a junior person.
 *   CIRCULATING them (`meeting.circulate`) — publishing a record other people will rely on and
 *   quote back. This is the one with consequences, and it belongs to the chair, not the typist.
 *
 * Once minutes are circulated they are frozen, for the same reason an issued memo is: they are
 * what the attendees were told the meeting decided. A correction is a new set of minutes, which is
 * how every organization that has ever kept them handles it.
 */
class MeetingPolicy extends ScopedPolicy
{
    protected string $module = 'meeting';

    /** Administer the meeting itself: move it between projects, reopen it, cancel it. */
    public function manage(User $user, Meeting $meeting): bool
    {
        return $this->check($user, 'manage', $meeting);
    }

    /**
     * Write or amend the minutes.
     *
     * Refused once they are circulated — see the class docblock — and refused on a cancelled
     * meeting, which by definition produced nothing to minute.
     */
    public function recordMinutes(User $user, Meeting $meeting): bool
    {
        if ($meeting->minutesCirculated() || $meeting->isCancelled()) {
            return false;
        }

        return $this->check($user, 'record_minutes', $meeting);
    }

    /**
     * Release the minutes to everyone who can reach the meeting.
     *
     * There must be minutes to release. Circulating an empty record would notify every attendee
     * about a document that does not exist, and the notification would be indistinguishable from
     * the real one when it later arrived.
     */
    public function circulate(User $user, Meeting $meeting): bool
    {
        if (! $meeting->hasMinutes() || $meeting->minutesCirculated() || $meeting->isCancelled()) {
            return false;
        }

        return $this->check($user, 'circulate', $meeting);
    }

    /** Read the minutes. Field-level, so it composes the record gate with the release gate. */
    public function viewMinutes(User $user, Meeting $meeting): bool
    {
        return $this->check($user, 'view', $meeting) && $meeting->minutesVisibleTo($user);
    }

    /**
     * Call the meeting off. Uses `meeting.edit`, matching the configured transition — cancelling is
     * a scheduling act, and the record survives it with a reason attached.
     */
    public function cancel(User $user, Meeting $meeting): bool
    {
        return ! $meeting->isCancelled() && $this->check($user, 'update', $meeting);
    }

    /**
     * May this actor invite this particular person?
     *
     * Question 1 is `meeting.edit` on the meeting. Question 2 is the same weakened test
     * MemoPolicy::address() uses and for the same reason: an invitation grants sight of one
     * appointment that the inviter arranged, not a channel into somebody's work. Restricting
     * invitations to the inviter's own subtree would prevent exactly the cross-department
     * coordination meetings exist for.
     *
     * The bounds that remain are the ones that are really about safety: same tenant (§75), and an
     * account that can actually log in.
     */
    public function invite(User $user, Meeting $meeting, ?User $candidate = null): bool
    {
        if (! $this->check($user, 'update', $meeting)) {
            return false;
        }

        if ($candidate === null) {
            return true;
        }

        return (int) $candidate->organization_id === (int) $user->organization_id
            && $candidate->canLogIn();
    }

    /**
     * The meeting record is frozen once its minutes are circulated. Rescheduling a meeting whose
     * minutes have been published would leave a record saying a meeting that happened on Tuesday
     * is scheduled for next month.
     */
    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Meeting && $record->minutesCirculated()) {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * A meeting that happened is cancelled or minuted, not deleted. Deleting is for one booked by
     * mistake — nothing recorded, nothing circulated.
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Meeting && ($record->hasMinutes() || $record->decisions()->exists())) {
            return false;
        }

        return parent::delete($user, $record);
    }
}
