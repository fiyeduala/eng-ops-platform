<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrgUnit;
use App\Models\User;

/**
 * The organization tree (§3, §72).
 *
 * Reading the chart is baseline for every employee — you cannot ask "who do I report to" without
 * it. Changing it is not: moving a department moves everyone's visibility with it, so
 * `org_unit.reorganize` is separate from `org_unit.edit` and is the permission the structure
 * editor's drag-and-drop actually checks.
 */
class OrgUnitPolicy extends ScopedPolicy
{
    protected string $module = 'org_unit';

    /**
     * Move a unit to a different parent, or reorder siblings.
     *
     * Requires reach over BOTH ends of the move. Checking only the unit being moved would let a
     * department administrator graft their department onto a division they cannot see — an
     * escalation dressed up as a reorganisation.
     */
    public function reorganize(User $user, OrgUnit $record, ?OrgUnit $newParent = null): bool
    {
        if (! $this->check($user, 'reorganize', $record)) {
            return false;
        }

        if ($newParent === null) {
            // Promoting a unit to the root of the tree restructures the whole organization.
            return $this->resolver->hasOrganizationWide($user, 'org_unit.reorganize');
        }

        return $this->check($user, 'reorganize', $newParent);
    }

    /**
     * A unit with people or children in it cannot be deleted — the records would be orphaned and
     * their owners' visibility would silently change. Reassign or archive first.
     */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->children()->exists() && ! $record->employees()->exists();
    }
}
