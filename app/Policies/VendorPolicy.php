<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

/**
 * Who may see, edit and — the two that matter — bank and blacklist a vendor.
 *
 * `viewBankDetails()` IS THE REASON THIS CLASS IS NOT THREE LINES. Everybody in procurement can see
 * a vendor; almost nobody should see where its money goes. Payment redirection fraud is a quiet
 * edit to an account number by somebody who legitimately holds `vendor.edit`, so the bank block
 * gets its OWN permission rather than riding on view or edit — which means an administrator has to
 * decide, explicitly, which handful of people in finance hold it (§6, §84).
 *
 * The read and the write are the same permission on purpose. Splitting them would produce the
 * arrangement where somebody may change an account number they are not allowed to read, which is
 * strictly worse than either half: they cannot check what was there before, and neither can they be
 * expected to notice they have replaced the wrong one.
 *
 * `blacklist()` IS NOT `edit`. Barring a company from every purchase order the organization will
 * ever raise is a commercial decision with a person's name against it, not a field change. Lifting
 * one is the same act in reverse and needs the same permission, so both route through here.
 *
 * A BLACKLISTED VENDOR IS STILL EDITABLE. Their address changes, their contacts leave, and freezing
 * the record would leave the register wrong about a company somebody may yet want to reinstate. It
 * is `canBeUsed()` on the model that procurement asks, not this policy.
 */
class VendorPolicy extends ScopedPolicy
{
    protected string $module = 'vendor';

    /**
     * See — and therefore change — the bank block.
     *
     * `view` is checked first and separately: a permission to read bank details is not a permission
     * to reach a vendor you otherwise could not, so this is an AND rather than an override.
     */
    public function viewBankDetails(User $user, Vendor $vendor): bool
    {
        return $this->check($user, 'view', $vendor) && $this->check($user, 'bank', $vendor);
    }

    public function updateBankDetails(User $user, Vendor $vendor): bool
    {
        return $this->viewBankDetails($user, $vendor);
    }

    /** Bar them from every future order. Its own permission — see the class docblock. */
    public function blacklist(User $user, Vendor $vendor): bool
    {
        return ! $vendor->is_blacklisted && $this->check($user, 'blacklist', $vendor);
    }

    public function liftBlacklist(User $user, Vendor $vendor): bool
    {
        return (bool) $vendor->is_blacklisted && $this->check($user, 'blacklist', $vendor);
    }

    /**
     * Record a performance score.
     *
     * `evaluate` rather than `edit`, because a supplier score is evidence in an award decision.
     * Whoever may correct a phone number should not, by that fact alone, be able to move the number
     * that decides who wins the next tender.
     */
    public function evaluate(User $user, Vendor $vendor): bool
    {
        return $this->check($user, 'evaluate', $vendor);
    }

    /** Record or renew the prequalification. The evidence behind "approved". */
    public function prequalify(User $user, Vendor $vendor): bool
    {
        return $this->check($user, 'manage', $vendor);
    }
}
