<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Role definitions (§5, §6).
 *
 * The rule that matters here is the last one: nobody may grant a permission they do not
 * themselves hold. Without it, `role.edit` is not one permission — it is every permission, one
 * edit away.
 */
class RolePolicy extends ScopedPolicy
{
    protected string $module = 'role';

    /**
     * Change which permissions a role carries.
     *
     * @param  array<string,mixed>  $grants  permission key => scope(s), as submitted
     */
    public function editGrants(User $user, Role $record, array $grants = []): bool
    {
        if (! $this->check($user, 'manage', $record)) {
            return false;
        }

        return $this->mayGrantAll($user, array_keys($grants));
    }

    /**
     * No escalation through the role editor.
     *
     * An administrator may only put into a role permissions they already hold. Otherwise the
     * whole access model reduces to a single question — can you edit roles — and every
     * bounded administrator (§10) is bounded only until they open this screen.
     *
     * The organization owner is exempt because they hold everything by definition; the check
     * below passes for them naturally, without a special case.
     *
     * @param  array<int,string>  $permissionKeys
     */
    public function mayGrantAll(User $user, array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if (! $this->resolver->has($user, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A role still assigned to someone cannot be deleted — the people holding it would silently
     * lose reach, and the audit trail would show a permission change nobody made.
     */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        if ($record->is_system) {
            return false;
        }

        return ! $record->users()->exists();
    }

    /**
     * Assign this role to someone. Separate from editing the role itself: a line manager may hand
     * out "Site Administrator" without being able to change what it means.
     */
    public function assign(User $user, Role $record): bool
    {
        return $this->check($user, 'assign', $record);
    }
}
