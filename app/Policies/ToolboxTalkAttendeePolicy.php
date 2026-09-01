<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ToolboxTalk;
use App\Models\ToolboxTalkAttendee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who was there. Reach is the parent talk's, and so is the freeze once it has been held.
 *
 * ONE EXCEPTION, AND IT IS THE WHOLE POINT OF THE TABLE: `sign`. An attendee confirming their own
 * attendance is not editing the record — it is the signature the record exists to collect — and it
 * must remain possible while the talk is being delivered, which is exactly when the parent is about
 * to freeze. So signing is authorized against the person, not against `edit`: you may sign your own
 * line, and only your own.
 */
class ToolboxTalkAttendeePolicy extends ScopedPolicy
{
    protected string $module = 'toolbox_talk';

    public function view(User $user, Model $record): bool
    {
        return $this->throughTalk($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughTalk($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughTalk($user, 'update', $record) ?? parent::delete($user, $record);
    }

    public function createFor(User $user, ToolboxTalk $talk): bool
    {
        return Gate::forUser($user)->allows('update', $talk);
    }

    /**
     * Sign for your own attendance.
     *
     * Requires the row to be about this user and to be unsigned. Nobody signs on anybody's behalf
     * through this route — a supervisor entering the list for a crew of subcontractors is creating
     * rows, which is `update` on the parent, and those rows carry `person_name` with no user and no
     * signature, which is an honest representation of what happened.
     */
    public function sign(User $user, ToolboxTalkAttendee $attendee): bool
    {
        if ($attendee->hasSigned() || (int) $attendee->user_id !== $user->getKey()) {
            return false;
        }

        $talk = $attendee->relationLoaded('toolboxTalk') ? $attendee->toolboxTalk : $attendee->toolboxTalk()->first();

        return $talk !== null && Gate::forUser($user)->allows('view', $talk);
    }

    private function throughTalk(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof ToolboxTalkAttendee) {
            return null;
        }

        $talk = $record->relationLoaded('toolboxTalk') ? $record->toolboxTalk : $record->toolboxTalk()->first();

        return $talk === null ? false : Gate::forUser($user)->allows($ability, $talk);
    }
}
