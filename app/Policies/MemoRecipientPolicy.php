<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MemoRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Distribution rows have no permissions of their own — they borrow the memo's, exactly as
 * TaskChecklistItemPolicy borrows the task's.
 *
 * PermissionCatalogue declares no `memo_recipient.*` keys, and inventing them here would produce
 * permissions no administrator could grant through the role editor.
 *
 * The one thing this class does in its own right is `markRead`: the read receipt belongs to the
 * person who read it, so the only account that may stamp a row is the account named on it. Not the
 * issuer, not an administrator, not somebody with `memo.edit` — a read receipt written by anyone
 * other than the reader is a fabricated record of who saw what, which is the one fact the whole
 * module exists to establish.
 */
class MemoRecipientPolicy extends ScopedPolicy
{
    protected string $module = 'memo';

    public function viewAny(User $user): bool
    {
        return $this->memos()->viewAny($user);
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

    /** Only the named reader, and only on a memo they can actually still open. */
    public function markRead(User $user, MemoRecipient $recipient): bool
    {
        if ((int) $recipient->user_id !== (int) $user->getKey()) {
            return false;
        }

        return $this->throughParent($user, $recipient, 'view');
    }

    private function throughParent(User $user, Model $record, string $ability): bool
    {
        if (! $record instanceof MemoRecipient) {
            return false;
        }

        $memo = $record->relationLoaded('memo') ? $record->memo : $record->memo()->first();

        return $memo !== null && $this->memos()->check($user, $ability, $memo);
    }

    private function memos(): MemoPolicy
    {
        return app(MemoPolicy::class);
    }
}
