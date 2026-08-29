<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Reference-number patterns (§64).
 *
 * `numbering.manage` is a narrow permission held by document controllers and system
 * administrators, and deliberately not by project managers: the format of a project number is a
 * company-wide convention that appears on drawings, transmittals and contracts, not a per-project
 * preference.
 *
 * Editing a scheme changes only what FUTURE numbers look like. Numbers already issued are stored
 * strings on their records and are never recomputed, so a company can change its convention in
 * March without renaming everything it sent a client in January.
 */
class NumberingSchemePolicy extends ScopedPolicy
{
    protected string $module = 'numbering';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'delete_permanently',
    ];
}
