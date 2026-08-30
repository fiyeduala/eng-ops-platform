<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Decisions borrow the meeting's permissions.
 *
 * Writing one is part of recording what the meeting did, so create, update and delete all resolve
 * through MeetingPolicy::recordMinutes() rather than through `meeting.edit`. That has a consequence
 * worth stating: once the minutes are circulated the decisions freeze with them, because
 * recordMinutes() refuses after circulation. A decision the meeting is recorded as having taken is
 * not something anybody edits afterwards — subsequent meetings supersede it, which is why
 * `sequence` and the meeting reference exist.
 */
class MeetingDecisionPolicy extends ScopedPolicy
{
    protected string $module = 'meeting';

    public function viewAny(User $user): bool
    {
        return $this->meetings()->viewAny($user);
    }

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof MeetingDecision) {
            return false;
        }

        $meeting = $this->parentOf($record);

        return $meeting !== null && $this->meetings()->check($user, 'view', $meeting);
    }

    public function create(User $user): bool
    {
        return $this->check($user, 'record_minutes');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughMinutes($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughMinutes($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->throughMinutes($user, $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    private function throughMinutes(User $user, Model $record): bool
    {
        if (! $record instanceof MeetingDecision) {
            return false;
        }

        $meeting = $this->parentOf($record);

        return $meeting !== null && $this->meetings()->recordMinutes($user, $meeting);
    }

    private function parentOf(MeetingDecision $record): ?Meeting
    {
        return $record->relationLoaded('meeting') ? $record->meeting : $record->meeting()->first();
    }

    private function meetings(): MeetingPolicy
    {
        return app(MeetingPolicy::class);
    }
}
