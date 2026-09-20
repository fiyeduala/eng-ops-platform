<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What a company asks of its own records (§73, docs/11 §1).
 *
 * OPENING A REPORT IS NOT SEEING ITS ROWS. This is the same separation KpiDefinitionPolicy draws
 * and it matters more here, because a report LOOKS like the records it lists in a way a KPI tile
 * does not. `report.view` grants exactly one thing: the right to open the builder's output and see
 * the question. Which rows come back is decided afresh by ReportRunner, through the reader's own
 * `visibleTo()`, every single run. Somebody holding `report.view` and nothing else opens the
 * company's incident report and correctly sees an empty table.
 *
 * WHICH IS WHY `view` IS NOT GATED ON THE REGISTER'S OWN PERMISSION. It would be the obvious
 * belt-and-braces move — "you may only open the incident report if you hold `hse_incident.view`" —
 * and it is the wrong one twice over. It would hide the report from the person whose department
 * has three visible incidents but no organization-wide grant, and it would imply the opposite of
 * what is true: that holding the register permission is what fills the table, rather than the
 * reader's scope within it.
 *
 * `export` IS ITS OWN ABILITY, AND DELIBERATELY NOT `view`. A report on screen is read by somebody
 * signed in, in a session that can be revoked, over records that are re-checked every refresh. A
 * CSV of the same rows is a file: it leaves with the person, outlives their employment, and is
 * re-read by people who were never authenticated at all. Separating them lets a company grant the
 * first without the second, which is the distinction most of them already make informally.
 *
 * `schedule` IS RESERVED. The permission catalogue names it; nothing maps to it here, because
 * scheduled delivery is Phase 8 and a mapped-but-unimplemented ability is a grant an administrator
 * makes believing it does something.
 */
class ReportDefinitionPolicy extends ScopedPolicy
{
    protected string $module = 'report';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        'restore' => 'edit',
        'forceDelete' => 'delete',
        'export' => 'export',
    ];

    /**
     * Taking the rows away as a file.
     *
     * DECLARED AS A METHOD, not left to the ability map alone. Laravel resolves an ability by
     * looking for a method of that name on the policy: an entry in `$abilityMap` says what
     * permission `export` corresponds to, but with no `export()` here the gate finds nothing to
     * call and denies — which is the safe failure, and an invisible one. Every custom ability in
     * this codebase therefore gets both halves, and `check()` puts it through the same five gates
     * as `view`, so the record's tenant, ACL and scope are all re-asked rather than assumed
     * because the person already had the page open.
     */
    public function export(User $user, Model $record): bool
    {
        return $this->check($user, 'export', $record);
    }
}
