<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Who a record-level ACL entry is granted to (Gate 4).
 */
enum GranteeType: string
{
    case User = 'user';
    case Role = 'role';
    case OrgUnit = 'org_unit';
    case Project = 'project';
    case ProjectRole = 'project_role';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Specific user',
            self::Role => 'Role',
            self::OrgUnit => 'Organization unit',
            self::Project => 'Project members',
            self::ProjectRole => 'Project role',
        };
    }
}
