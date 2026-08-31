<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transmittal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Transmittals (§38).
 *
 * The same draft/issued split as memos, and for a stronger reason. A transmittal is the record that
 * settles a commercial argument: what we sent, which revision, on what date, and whether they said
 * they got it. Editing one after it went out would mean the copy in the file no longer has to match
 * the copy in the recipient's inbox, which destroys the only property that made it worth keeping.
 *
 * So: ISSUED TRANSMITTALS CANNOT BE EDITED OR DELETED, by anyone, at any scope. Cancelling exists
 * for "that went out in error" and leaves the original readable with the cancellation attached.
 *
 * ACKNOWLEDGEMENT is a separate permission from issuing on purpose. The document controller sends
 * it; recording the client's confirmation of receipt is often somebody else's job entirely, and in
 * some organizations is the one thing an otherwise read-only project administrator may do.
 */
class TransmittalPolicy extends ScopedPolicy
{
    protected string $module = 'transmittal';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Transmittal && $record->isIssued()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Transmittal && $record->isIssued()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** The one-way door. Only from draft, and only with `transmittal.issue`. */
    public function issue(User $user, Transmittal $transmittal): bool
    {
        return $transmittal->isDraft() && $this->check($user, 'issue', $transmittal);
    }

    public function acknowledge(User $user, Transmittal $transmittal): bool
    {
        return $transmittal->isIssued()
            && ! $transmittal->isAcknowledged()
            && $this->check($user, 'acknowledge', $transmittal);
    }

    /**
     * Void an issued transmittal.
     *
     * Needs `transmittal.manage` rather than `issue`, because withdrawing a formal issue is a
     * heavier act than making one — it says a communication the recipient has already acted on
     * should not have gone.
     */
    public function cancel(User $user, Transmittal $transmittal): bool
    {
        return $this->check($user, 'manage', $transmittal);
    }

    /**
     * The cover sheet PDF.
     *
     * Shares `view` rather than borrowing document.download: the sheet is a rendering of this
     * record's own fields, and the documents it lists are each authorized separately when somebody
     * actually asks for one (§67).
     */
    public function coverSheet(User $user, Transmittal $transmittal): bool
    {
        return $this->check($user, 'view', $transmittal);
    }
}
