<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * The project type catalogue.
 *
 * Governed by the `project` module rather than one of its own: reading a type is reading a label
 * every project page needs, and editing the list is project administration. Adding
 * `project_type.*` to the catalogue would put four more checkboxes in front of every
 * administrator to express something `project.manage` already says.
 */
class ProjectTypePolicy extends ScopedPolicy
{
    protected string $module = 'project';

    /**
     * Every write folds onto `project.manage`. Note what this excludes: a project manager holding
     * `project.edit` on their own project cannot rewrite the company's vocabulary from inside it.
     *
     * @var array<string,string>
     */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'delete_permanently',
    ];

    /** A type still describing projects cannot be removed; their descriptions would go blank. */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->projects()->exists();
    }
}
