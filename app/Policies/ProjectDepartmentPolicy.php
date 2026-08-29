<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Which departments are committed to a project (§13, step one of the cascade).
 *
 * Attaching a department is administration OF the project, so it folds onto `project.manage`
 * rather than onto the team permissions. The distinction is real and worth keeping: a department
 * head holding `project_member.assign` staffs the projects their department has been given; they
 * do not get to volunteer their department for new ones.
 *
 * Reading the list is `project.view`, because "who is working on this" is part of the project.
 */
class ProjectDepartmentPolicy extends ScopedPolicy
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
}
