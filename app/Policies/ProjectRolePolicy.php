<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * The project-role catalogue (§14).
 *
 * Same shape as ProjectTypePolicy, and for the same reason. Worth stating explicitly: editing
 * this list confers NO permissions on anyone. A project role says what a person does on a job;
 * what they may do to records is decided by their Roles, through the permission catalogue.
 * The one thing `is_lead` does affect is workflow routing, which is why changing it is
 * `project.manage` and not something a team member can do.
 */
class ProjectRolePolicy extends ScopedPolicy
{
    protected string $module = 'project';

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

    /** A role people still hold cannot be removed; their assignments would lose their meaning. */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->members()->exists();
    }
}
