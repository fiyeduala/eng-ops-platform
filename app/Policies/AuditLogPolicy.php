<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * The audit trail (§59).
 *
 * Read-only by construction, at every layer: AuditLog's model events throw on update and delete,
 * the table has no updated_at, and this policy refuses every write ability. Three independent
 * refusals, because an audit trail that the people it audits can edit is not an audit trail.
 *
 * `audit_log.view` is marked sensitive in the catalogue — reading it reveals who did what,
 * everywhere — so it is granted to very few roles, and never inherited from the baseline.
 */
class AuditLogPolicy extends ScopedPolicy
{
    protected string $module = 'audit_log';

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $record): bool
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
