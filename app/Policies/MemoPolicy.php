<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Memo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Memos (§17).
 *
 * The five gates are inherited. What this class adds is the difference between a draft and a
 * document that has been sent, which no CRUD policy expresses:
 *
 *   ISSUED MEMOS CANNOT BE EDITED. By anyone, at any scope, including whoever issued it. The text
 *   of a memo is what its recipients received and acted on; silently rewriting it afterwards makes
 *   the record a liability rather than evidence. Retraction has its own act — withdraw — which
 *   says so out loud and leaves the original readable.
 *
 *   ISSUED MEMOS CANNOT BE DELETED, for the same reason. `delete` is for a draft nobody has seen.
 *
 * Compare AnnouncementPolicy, which deliberately DOES allow a published notice to be edited. The
 * difference is that a notice is a live statement on a board, kept accurate; a memo is a dated
 * communication to named people, kept as it was sent.
 */
class MemoPolicy extends ScopedPolicy
{
    protected string $module = 'memo';

    /**
     * Issue the memo — the act that turns a draft into a communication and hands every recipient
     * sight of it.
     *
     * State check as well as permission: re-issuing an issued memo is not a thing, and offering it
     * would produce a second set of notifications for a document nobody changed.
     */
    public function issue(User $user, Memo $memo): bool
    {
        return $memo->isDraft() && $this->check($user, 'issue', $memo);
    }

    /**
     * Pull an issued memo back.
     *
     * Shares `memo.issue` with issuing, matching the configured transition: whoever decides a memo
     * goes out decides it comes back. Not a delete — see the class docblock.
     */
    public function withdraw(User $user, Memo $memo): bool
    {
        return $memo->isIssued()
            && $memo->statusKey() !== 'withdrawn'
            && $this->check($user, 'issue', $memo);
    }

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Memo && $record->isIssued()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Memo && $record->isIssued()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * May this actor put this particular person on this memo's distribution?
     *
     * Question 1 is `memo.edit` on the memo — the distribution list is part of the document, so
     * whoever may change the document may change who it goes to.
     *
     * Question 2 is deliberately WEAKER than TaskPolicy::canCommit(), and the reason is worth
     * stating because the two look like they should match. Assigning work to somebody senior is an
     * escalation: the task becomes a channel, and everything later attached to it — comments,
     * files, the assignee's own replies — flows back to whoever made the assignment. Addressing a
     * memo grants sight of ONE document, whose entire content the sender already wrote. There is
     * nothing to escalate into. Bounding recipients to the sender's own subtree would only mean
     * that nobody could write to the department next door, which is what memos are for.
     *
     * What remains is what actually matters: the recipient must belong to this tenant (§75) and
     * must be an account that can log in. A memo addressed to a disabled account is a grant handed
     * to a credential nobody should be using.
     */
    public function address(User $user, Memo $memo, ?User $candidate = null): bool
    {
        if (! $this->check($user, 'update', $memo)) {
            return false;
        }

        if ($candidate === null) {
            return true;
        }

        return (int) $candidate->organization_id === (int) $user->organization_id
            && $candidate->canLogIn();
    }

    /**
     * Taking somebody off the list revokes rather than grants, so it asks question 1 alone —
     * the mirror of TaskPolicy::assign() with a null candidate.
     *
     * Only while the memo is a draft: once people have been told, quietly removing them from the
     * record of who was told is falsification.
     *
     * That draft test is written out here rather than left to `update()`. It would be easy to
     * assume check(update) inherits this class's issued-memo refusal, but it does not — check() is
     * the five gates and the `memo.edit` permission, and it never calls the overridden update()
     * above. Adding a name to an issued memo IS still allowed (MemoService grants the late
     * recipient the same access the original ones got); taking one off is not.
     */
    public function unaddress(User $user, Memo $memo): bool
    {
        return $memo->isDraft() && $this->check($user, 'update', $memo);
    }
}
