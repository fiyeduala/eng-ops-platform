<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ToolboxTalk;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The five minutes before the shift starts.
 *
 * THE ATTENDANCE LIST IS THE RECORD. The topic is administratively useful; the reason this register
 * exists is to be able to say, afterwards, that these named people were briefed on this subject at
 * this time. That is what an inspector asks for and what a defence rests on.
 *
 * A HELD TALK IS FROZEN. Once `held_at` is stamped, neither the content nor the attendance may be
 * edited: a briefing whose subject matter can be revised after the fact proves nothing about what
 * anybody was told. Corrections before it is delivered are ordinary editing.
 *
 * NO SEPARATE `deliver` ABILITY. The catalogue gives this module CRUD plus `manage`, and adding one
 * here would put a permission in the system that no role template grants and no administrator can
 * see (§32). Recording that the talk happened is `edit` on the record, which is exactly right: the
 * presenter is the assignee, so `assigned` scope lets them record their own and nobody else's.
 */
class ToolboxTalkPolicy extends ScopedPolicy
{
    protected string $module = 'toolbox_talk';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof ToolboxTalk && $record->wasHeld()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof ToolboxTalk && $record->wasHeld()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Record that the talk was delivered, which is the moment it freezes. */
    public function record(User $user, ToolboxTalk $talk): bool
    {
        return ! $talk->wasHeld() && $this->check($user, 'update', $talk);
    }
}
