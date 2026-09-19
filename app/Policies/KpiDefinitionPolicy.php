<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * What a company counts (§56, §73).
 *
 * READING THE LIST IS NOT READING THE NUMBERS. Holding `kpi.view` lets somebody see that a measure
 * exists and how it is defined; it grants nothing at all about the figure, which KpiEvaluator counts
 * afresh through that person's own visibility every time it is asked for. The two are deliberately
 * separate: an administrator has to be able to inspect a definition — "why does this tile say
 * eleven" is not answerable otherwise — without that inspection becoming a way to read a total over
 * records they cannot open (§61).
 *
 * MANAGING IS THE DANGEROUS HALF, and not for the obvious reason. Nothing breaks when a KPI is
 * edited: no record moves, no screen errors, no job fails. What changes is the number a board reads
 * next quarter. Widening a filter by one condition turns "overdue deliverables" into a figure that
 * is still called "overdue deliverables" and is now wrong, and the only trace is the audit trail —
 * which is why the model is audited and why this sits behind `manage` rather than `edit`.
 */
class KpiDefinitionPolicy extends ScopedPolicy
{
    protected string $module = 'kpi';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'manage',
        'export' => 'export',
    ];
}
