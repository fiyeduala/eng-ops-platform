<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Announcements (§18).
 *
 * Two things differ from MemoPolicy, and both follow from what a noticeboard is:
 *
 *   A PUBLISHED NOTICE MAY STILL BE EDITED. A memo is frozen on issue because it is a dated
 *   communication to named people. A notice is a live statement — "the north gate is closed until
 *   Friday" becomes "until Monday" — and forcing a second notice for every correction produces a
 *   board nobody reads. Every edit is still recorded by the activity engine, so the history of
 *   what the board said is recoverable even though the board itself shows only the current text.
 *
 *   THERE IS NO CONFIDENTIALITY GATE, because the model carries no confidentiality column. See the
 *   Announcement docblock: anything that must be withheld from part of the company is a memo.
 *   Gate 5 simply finds nothing to apply, which is the intended behaviour and not an oversight.
 *
 * Archiving is terminal. An archived notice is history, and history is not edited.
 */
class AnnouncementPolicy extends ScopedPolicy
{
    protected string $module = 'announcement';

    /** Put it on the board. */
    public function publish(User $user, Announcement $announcement): bool
    {
        return $announcement->isDraft() && $this->check($user, 'publish', $announcement);
    }

    /**
     * Take it back off the board and return it to draft.
     *
     * Shares `announcement.publish`, matching the configured transition. The publication column is
     * cleared by the service, so an unpublished notice goes back behind the draft curtain — which
     * is the honest outcome: it is no longer something the company has been told.
     */
    public function unpublish(User $user, Announcement $announcement): bool
    {
        return $announcement->isPublished()
            && $announcement->statusKey() !== 'archived'
            && $this->check($user, 'publish', $announcement);
    }

    /** Retire it. Terminal, and the only way a notice leaves the board permanently. */
    public function archive(User $user, Announcement $announcement): bool
    {
        return $announcement->isPublished()
            && $announcement->statusKey() !== 'archived'
            && $this->check($user, 'publish', $announcement);
    }

    /**
     * Change who a live notice is aimed at.
     *
     * Its own ability because it is its own act. `publish()`, `unpublish()` and `archive()` each ask
     * about a state the notice is in or is leaving; re-aiming asks nothing about state and everything
     * about audience — moving a notice from one department to another broadcasts it at people who
     * were never in its audience, which is the same decision as publishing it there in the first
     * place and is not the same decision as correcting its text.
     *
     * Asking `publish()` here instead would refuse everybody: a live notice is not a draft.
     */
    public function readdress(User $user, Announcement $announcement): bool
    {
        return $announcement->statusKey() !== 'archived'
            && $this->check($user, 'publish', $announcement);
    }

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Announcement && $record->statusKey() === 'archived') {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * A notice the company has already read is archived, never deleted — the same reasoning that
     * makes an issued memo undeletable. Deleting is for a draft.
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Announcement && $record->isPublished()) {
            return false;
        }

        return parent::delete($user, $record);
    }
}
