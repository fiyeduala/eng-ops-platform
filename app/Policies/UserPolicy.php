<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Login accounts, as distinct from the personnel record (§4).
 *
 * Everything here is a privilege-escalation surface, so the extra abilities are deliberately
 * narrow and several of them refuse to act on the actor themselves.
 */
class UserPolicy extends ScopedPolicy
{
    protected string $module = 'user';

    /**
     * Grant or remove a role.
     *
     * Requires `role.assign`, NOT merely `user.edit`. Editing someone's phone number and deciding
     * what they can reach are different authorities, and a role that conflated them would let
     * every line manager mint administrators.
     */
    public function assignRoles(User $user, User $target): bool
    {
        if (! $this->check($user, 'view', $target)) {
            return false;
        }

        // Nobody edits their own roles. The check is on the ACTOR, so an administrator cannot
        // quietly widen their own reach — someone else with the authority has to do it, which is
        // what makes the audit entry meaningful.
        if ((int) $target->id === (int) $user->id) {
            return false;
        }

        // The owner account is not re-rolled by ordinary administrators.
        if ($target->is_super_admin && ! $user->is_super_admin) {
            return false;
        }

        return $this->resolver->has($user, 'role.assign');
    }

    /**
     * Force a password reset.
     *
     * Whoever can reset a password can take over the account, so this is its own permission and
     * is barred against the owner — otherwise a lesser administrator could seize the tenant.
     */
    public function resetPassword(User $user, User $target): bool
    {
        if ($target->is_super_admin && ! $user->is_super_admin) {
            return false;
        }

        return $this->check($user, 'reset_password', $target);
    }

    /**
     * Sign in as another person to reproduce what they see.
     *
     * The most dangerous ability in the system: it borrows someone else's entire reach. Barred
     * against yourself (pointless), against the owner (escalation), and requires the permission
     * at organization scope — an impersonation right that only reached your own department would
     * still be an impersonation right, and scoping it narrowly gives false comfort.
     */
    public function impersonate(User $user, User $target): bool
    {
        if ((int) $target->id === (int) $user->id) {
            return false;
        }

        if ($target->is_super_admin) {
            return false;
        }

        if (! $this->check($user, 'impersonate', $target)) {
            return false;
        }

        return $this->resolver->hasOrganizationWide($user, 'user.impersonate');
    }

    /**
     * Disable an account. Barred against yourself, so nobody can lock themselves out and, more
     * importantly, so an administrator under investigation cannot disable their own audit trail's
     * subject mid-review.
     */
    public function delete(User $user, $record): bool
    {
        if ((int) $record->id === (int) $user->id) {
            return false;
        }

        if ($record->is_super_admin && ! $user->is_super_admin) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** The owner's account is editable only by another owner. */
    public function update(User $user, $record): bool
    {
        if ($record->is_super_admin && ! $user->is_super_admin) {
            return false;
        }

        return parent::update($user, $record);
    }
}
