<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Sites, offices, yards and warehouses.
 *
 * Reading the list is baseline for everyone (a site appears on your own attendance record);
 * creating and editing one is administrative. See Location::accessOrganizationWideAbilities().
 */
class LocationPolicy extends ScopedPolicy
{
    protected string $module = 'location';

    /** A location still referenced by people cannot be deleted. */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->employees()->exists();
    }
}
