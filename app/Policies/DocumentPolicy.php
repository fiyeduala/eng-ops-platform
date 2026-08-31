<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Documents (§26, §27, §67).
 *
 * The five gates are inherited and answer "may this person see this register, this project, this
 * confidentiality level". What this class adds is everything the gates cannot know: the difference
 * between a draft and an approved revision, between a document sitting still and one halfway
 * through an approval route, and between reading a page and taking the file away.
 *
 * DOWNLOAD IS ITS OWN PERMISSION, not a consequence of `view`. §67 asks for it and the distinction
 * is real: a reviewer who may read a confidential procedure on screen is not automatically somebody
 * who may put a copy of it on a laptop. Every file-serving route authorizes `download` explicitly,
 * and the vault has no publicly reachable path at all.
 *
 * DELETING A CONTROLLED DOCUMENT WITH AN APPROVED REVISION IS REFUSED HERE, to everyone, at every
 * scope, including whoever owns it. A document that has been approved was relied on by somebody:
 * it was issued, transmitted, built to. `archive` exists for "we do not use this any more" and
 * keeps it readable. Delete is for a draft raised by mistake.
 *
 * CHECK-OUT IS NOT A SECURITY CONTROL, and this policy is careful not to pretend otherwise. It
 * denies `update` while somebody else holds the document open, which is what makes the UI honest,
 * but DocumentService enforces the same rule again — because a coordination lock that only exists
 * in a policy is a lock any second code path forgets about.
 */
class DocumentPolicy extends ScopedPolicy
{
    protected string $module = 'document';

    /**
     * `restore` maps to the module's own permission rather than borrowing `delete`.
     *
     * Documents are the one module where putting a record back is a distinct act with a distinct
     * audience: the person who may bin their own draft is not necessarily the document controller
     * who decides an archived procedure comes back into circulation.
     *
     * @var array<string,string>
     */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        'restore' => 'restore',
        'forceDelete' => 'delete_permanently',
    ];

    // ------------------------------------------------------------------ state-aware overrides

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Document && $record->isLockedByAnother($user)) {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * A document that has ever been approved cannot be deleted. See the class docblock.
     *
     * Deliberately written as a flat refusal before the gates rather than as a permission somebody
     * could be granted, because there is no role for which this should be true.
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Document) {
            if ($record->isLockedByAnother($user)) {
                return false;
            }

            if ($record->versions()->whereNotNull('approved_at')->exists()) {
                return false;
            }
        }

        return parent::delete($user, $record);
    }

    // ------------------------------------------------------------------ editing the content

    /**
     * May this person start a new revision?
     *
     * Distinct from `update`, which covers the document's metadata — its title, its register, who
     * owns it. Writing a new revision is the substantive act, and a document in flight is closed to
     * it: adding a draft underneath a route that is already collecting approvals would leave the
     * approvers signing something other than what ends up current.
     */
    public function createVersion(User $user, Document $document): bool
    {
        return ! $document->isInWorkflow()
            && ! $document->isLockedByAnother($user)
            && $this->check($user, 'update', $document);
    }

    /**
     * Take the document out for editing.
     *
     * Anyone who may edit it may hold it. Taking over an EXPIRED lock is allowed by the same test,
     * because `isLockedByAnother` treats an expired lock as no lock — somebody went on leave with
     * the document open and the register must not stay frozen until they come back. The takeover is
     * recorded on the timeline, which is the control that actually matters here.
     */
    public function checkOut(User $user, Document $document): bool
    {
        return ! $document->isLockedByAnother($user)
            && $this->check($user, 'update', $document);
    }

    /** Put it back. Your own lock, or somebody else's if you administer the module. */
    public function checkIn(User $user, Document $document): bool
    {
        if ($document->isLockedBy($user)) {
            return $this->check($user, 'update', $document);
        }

        return $this->check($user, 'manage', $document);
    }

    // ------------------------------------------------------------------ the route

    /**
     * Send the current draft for approval.
     *
     * Three things have to be true, and only one of them is a permission: there must be a draft to
     * send, the document must not already be in flight, and the actor must hold `document.submit`.
     * The state tests come first so the button disappears rather than erroring.
     */
    public function submit(User $user, Document $document): bool
    {
        return ! $document->isInWorkflow()
            && $document->versions()->whereNull('approved_at')->exists()
            && $this->check($user, 'submit', $document);
    }

    /**
     * Pull a document back out of an approval route before it finishes.
     *
     * The owner may always withdraw their own submission — they are the person the route is waiting
     * on behalf of. Anyone else needs `document.manage`, because cancelling somebody else's
     * approval discards work other people have already done on it.
     */
    public function withdraw(User $user, Document $document): bool
    {
        if (! $document->isInWorkflow()) {
            return false;
        }

        if ((int) $document->owner_id === (int) $user->getKey() || (int) $document->created_by === (int) $user->getKey()) {
            return $this->check($user, 'submit', $document);
        }

        return $this->check($user, 'manage', $document);
    }

    // ------------------------------------------------------------------ getting a copy out

    /**
     * §67. Fetching the file, as opposed to reading the page.
     *
     * Every route that streams from the vault calls this, and the vault is on the private disk with
     * no URL of its own — so there is exactly one door and it is guarded.
     */
    public function download(User $user, Document $document): bool
    {
        return $this->check($user, 'download', $document);
    }

    public function print(User $user, Document $document): bool
    {
        return $this->check($user, 'print', $document);
    }

    /**
     * Grant somebody outside the normal reach sight of this document — a record ACL row (Gate 4).
     *
     * Sharing is deliberately separate from editing. Someone who may correct a typo in a procedure
     * is not necessarily someone who may hand it to a contractor, and §84's "never trust the
     * frontend" applies hardest to the screen that widens access.
     */
    public function share(User $user, Document $document): bool
    {
        return $this->check($user, 'share', $document);
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->check($user, 'archive', $document);
    }

    /**
     * Signing is not approving.
     *
     * A stage whose action is `sign` produces a Signature — a named, timestamped, hashed assertion
     * that this person put their name to this revision. It carries a separate permission because
     * plenty of organizations let a coordinator approve a document while reserving the signature
     * for a chartered engineer.
     */
    public function sign(User $user, Document $document): bool
    {
        return $this->check($user, 'sign', $document);
    }
}
