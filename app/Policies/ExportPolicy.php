<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

/**
 * Who may collect a file (§61, §67).
 *
 * THIS POLICY READS NO PERMISSION, AND THAT IS THE DESIGN RATHER THAN AN OVERSIGHT. Every other
 * policy in this codebase extends ScopedPolicy and asks the access engine which scope a role holds
 * over a module. This one asks one question — "did you ask for this file?" — and nothing else.
 *
 * THE REASON IS WHAT IS INSIDE THE FILE. An export's rows were chosen by `visibleTo($requester)` at
 * the moment of the click, so the file is that one person's reach, frozen, with nothing on it that
 * explains whose reach it was. Handing it to a second reader is not a smaller version of the
 * disclosure problem — it is a stranger version of it. A managing director downloading a site
 * clerk's export gets FEWER rows than their own permissions allow, under the report's full name, and
 * quotes the total. A site clerk downloading a director's export gets more rows than they may see,
 * and neither the file nor this platform ever told them so. Only the first of those is a security
 * breach; both are the platform lying about a number, which is the failure docs/11 is written
 * against.
 *
 * SO `report.export` IS NOT CONSULTED HERE EITHER. That permission governs whether somebody may
 * CREATE an export, and ReportDefinitionPolicy asks it at that moment with the report in hand.
 * Re-asking it at download time would let a revoked permission strand a file its owner is already
 * holding a notification for, and — worse — would let anybody who still holds it collect somebody
 * else's. The grant makes files; ownership collects them.
 *
 * WHAT THIS POLICY DOES NOT DECIDE is whether the file still exists or whether its retention has run
 * out. Those are the export's own state (`isCollectable()`) and the controller's 404, not an
 * authorization question — an expired file is refused to its owner as well.
 */
class ExportPolicy
{
    /**
     * Seeing the list of one's own exports.
     *
     * Always true for a signed-in user, because the list is filtered to them by `ownedBy()` and a
     * person with no exports correctly sees an empty page. There is no permission that could make
     * this false without also making it impossible to collect a file somebody was invited to
     * collect.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Export $export): bool
    {
        return $this->owns($user, $export);
    }

    public function download(User $user, Export $export): bool
    {
        return $this->owns($user, $export);
    }

    /**
     * Throwing one away early.
     *
     * The owner may, and only the owner. A person who has finished with a file should be able to
     * remove it rather than wait for retention — the whole point of the retention window is that
     * nobody wants these lying about, and an owner acting sooner is the policy working.
     */
    public function delete(User $user, Export $export): bool
    {
        return $this->owns($user, $export);
    }

    /**
     * The tenant check is here as well as on the ownership check, and not because it is likely to
     * fire.
     *
     * `requested_by` alone would be sufficient: user ids are global and a colleague at another
     * company cannot hold this one's id. It is written anyway because an id comparison is the kind
     * of line somebody later replaces with an email or an employee number while tidying, and those
     * are not globally unique (§75).
     */
    private function owns(User $user, Export $export): bool
    {
        return (int) $export->requested_by === (int) $user->getKey()
            && (int) $export->organization_id === (int) $user->organization_id;
    }
}
