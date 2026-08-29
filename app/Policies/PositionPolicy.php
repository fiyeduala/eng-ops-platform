<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Job titles. Describes the JOB; system access is a Role.
 */
class PositionPolicy extends ScopedPolicy
{
    protected string $module = 'position';

    /** A position still held by someone cannot be deleted; it would orphan their job title. */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->employees()->exists();
    }
}
