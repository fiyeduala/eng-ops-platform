<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The moves allowed between statuses (§63).
 *
 * Governed by the same `status` module as the vocabulary itself, because the two are one
 * configuration: a status nothing can move to is decoration, and a transition to a status that
 * does not exist is nothing at all.
 *
 * This policy decides who may EDIT the whitelist. Who may USE one of its rows is a different
 * question, answered per row by `permission_key` and enforced in StatusMachine — which is why a
 * company can say "only QA closes an NCR" without anyone touching a policy.
 */
class StatusTransitionPolicy extends ScopedPolicy
{
    protected string $module = 'status';

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
