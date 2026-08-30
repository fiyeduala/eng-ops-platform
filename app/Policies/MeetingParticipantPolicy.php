<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Invitation and attendance rows borrow the meeting's permissions, like every other child record
 * in this system (see TaskChecklistItemPolicy for the reasoning).
 *
 * `respond` is the exception that earns its own method: submitting apologies is something the
 * invitee does about their own attendance, and nobody else may do it for them. An apology recorded
 * by a third party is a statement put in someone's mouth.
 *
 * Marking who actually ATTENDED is not that — it is the secretary's job, part of recording what
 * happened, and therefore rides on `meeting.record_minutes` through the parent.
 */
class MeetingParticipantPolicy extends ScopedPolicy
{
    protected string $module = 'meeting';

    public function viewAny(User $user): bool
    {
        return $this->meetings()->viewAny($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'view');
    }

    public function create(User $user): bool
    {
        return $this->check($user, 'update');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'update');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'update');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    /** Send apologies for my own invitation, and nobody else's. */
    public function respond(User $user, MeetingParticipant $participant): bool
    {
        if ((int) $participant->user_id !== (int) $user->getKey()) {
            return false;
        }

        return $this->throughParent($user, $participant, 'view');
    }

    /** Take the register. Part of recording the meeting, not of arranging it. */
    public function recordAttendance(User $user, MeetingParticipant $participant): bool
    {
        $meeting = $this->parentOf($participant);

        return $meeting !== null && $this->meetings()->recordMinutes($user, $meeting);
    }

    private function throughParent(User $user, Model $record, string $ability): bool
    {
        if (! $record instanceof MeetingParticipant) {
            return false;
        }

        $meeting = $this->parentOf($record);

        return $meeting !== null && $this->meetings()->check($user, $ability, $meeting);
    }

    private function parentOf(MeetingParticipant $record): ?Meeting
    {
        return $record->relationLoaded('meeting') ? $record->meeting : $record->meeting()->first();
    }

    private function meetings(): MeetingPolicy
    {
        return app(MeetingPolicy::class);
    }
}
