<?php

declare(strict_types=1);

namespace App\Policies;

use App\Engines\Access\AccessResolver;
use App\Engines\Access\ActorProfile;
use App\Models\SavedFilter;
use App\Models\User;

/**
 * Who may keep, share and remove a named question (§54).
 *
 * THIS POLICY DOES NOT EXTEND ScopedPolicy, AND THAT IS THE ONE THING WORTH EXPLAINING HERE, because
 * §78's warning about second engines applies to access as much as to approval and the exception has
 * to earn itself.
 *
 * ScopedPolicy answers "may this person reach this company record", and every gate it runs is about
 * company data: a permission from the catalogue, a reach that runs downward through the org tree, a
 * confidentiality band, an ACL row. A saved filter is none of those. It is a person's own bookmark.
 * There is no `saved_filter.view` permission and there should not be one — a company that had to
 * grant the right to name your own shortlist would be configuring something it does not think about.
 * Running a bookmark through the record engine would mean inventing a permission nobody asked for
 * and a reach rule that answers a question the engine was not built for.
 *
 * SO WHAT IS LEFT IS OWNERSHIP AND TENANCY, and both are enforced here rather than assumed:
 *
 *   Gate 0  the account is usable and its company is not suspended — copied from ScopedPolicy
 *           deliberately, because a suspended tenant must mean the same thing on every path.
 *   Gate 1  the filter belongs to the actor's company. Never skipped, never overridable (§75).
 *
 * AND CRUCIALLY, NONE OF THIS TOUCHES THE ROWS. Passing `view` here means the person may see the
 * QUESTION — its name and its conditions. The records it selects are fetched afterwards by the
 * runner, through the reader's own `visibleTo()`, and a filter that passes every gate below still
 * returns an empty table to somebody with no reach. Sharing a view is not sharing a result.
 *
 * DELETING A SHARE IS NOT ONLY THE OWNER'S. A filter shared company-wide by somebody who has since
 * left is on everybody's screen with nobody able to take it down, and "wait for the owner" is not an
 * answer when the owner's account is disabled. So a person who may build reports for this company —
 * the same authority the service requires to make a company-wide share in the first place — may also
 * remove one. A PRIVATE filter is nobody else's business and is exempt: it is on one screen, it
 * discloses nothing, and an administrator tidying up other people's bookmarks is a power with no
 * problem behind it.
 */
class SavedFilterPolicy
{
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly ActorProfile $actors,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->usable($user);
    }

    public function create(User $user): bool
    {
        return $this->usable($user);
    }

    /**
     * Seeing the question — yours, your department's, or the company's.
     *
     * The three cases are the ones `SavedFilter::scopeReadableBy()` puts into SQL, and they are
     * written out again here rather than delegated to it. The list query and the single-record
     * check have to agree, and the way they are made to agree in this codebase is that both are
     * short enough to read side by side; a filter that appeared in a list but 403'd when applied,
     * or worse applied while absent from every list, is the failure ScopedPolicy's own comment
     * warns about.
     */
    public function view(User $user, SavedFilter $filter): bool
    {
        if (! $this->usable($user) || ! $this->sameOrganization($user, $filter)) {
            return false;
        }

        if ($filter->ownedBy($user)) {
            return true;
        }

        if ($filter->visibility === 'organization') {
            return true;
        }

        if ($filter->visibility === 'org_unit' && $filter->org_unit_id !== null) {
            // Upward, for the reason the model's scope gives: a filter shared with Engineering is
            // meant for the people inside Engineering, which includes the ones two levels down in
            // Civil. This is routing, not reach — reach runs downward and runs on the rows.
            return in_array(
                (int) $filter->org_unit_id,
                array_map('intval', $this->actors->ancestorOrgUnitIds($user)),
                true,
            );
        }

        return false;
    }

    /**
     * Changing it — the owner alone.
     *
     * Not extended to administrators. Editing somebody else's saved view means changing what the
     * name on their screen selects, silently: they carry on clicking "Open in Civil" and get a
     * different list. Removing it is visible; rewriting it is not.
     */
    public function update(User $user, SavedFilter $filter): bool
    {
        return $this->usable($user)
            && $this->sameOrganization($user, $filter)
            && $filter->ownedBy($user);
    }

    public function delete(User $user, SavedFilter $filter): bool
    {
        if (! $this->usable($user) || ! $this->sameOrganization($user, $filter)) {
            return false;
        }

        if ($filter->ownedBy($user)) {
            return true;
        }

        return $filter->isShared() && $this->resolver->has($user, 'report.create');
    }

    // ================================================================== gates 0 and 1

    private function usable(User $user): bool
    {
        return $user->canLogIn() && $this->resolver->tenantIsActive($user);
    }

    private function sameOrganization(User $user, SavedFilter $filter): bool
    {
        return (int) $filter->organization_id === (int) $user->organization_id;
    }
}
