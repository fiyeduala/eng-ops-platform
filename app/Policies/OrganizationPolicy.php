<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * The tenant's own profile.
 *
 * Gate 1 for an Organization — "is this MY organization" — is handled once in
 * ScopedPolicy::sameOrganization(), so nothing here needs to repeat it.
 *
 * Deliberately has no create or delete ability. Provisioning and removing a tenant are platform
 * operations (OrganizationProvisioner, run from the console), not something any role inside a
 * company can reach: a company cannot delete itself through its own admin screens, and no
 * customer's administrator can conjure a second tenant.
 */
class OrganizationPolicy extends ScopedPolicy
{
    protected string $module = 'organization';

    /** Branding, working week, numbering formats, workflow defaults — `organization.configure`. */
    public function configure(User $user, Organization $record): bool
    {
        return $this->check($user, 'configure', $record);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, $record): bool
    {
        return false;
    }

    public function restore(User $user, $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, $record): bool
    {
        return false;
    }
}
