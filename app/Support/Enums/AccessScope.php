<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * The reach of a permission grant.
 *
 * Scopes are NOT a single ordered ladder. There are two axes:
 *   - the organizational axis (own → assigned → team → section → department → division → organization)
 *   - the project axis (project)
 *
 * A user holding both DEPARTMENT and PROJECT sees the UNION of both. This is what allows an
 * engineer to see all Engineering documents AND everything project-wide on their projects,
 * without seeing another department's confidential records. See docs/04-access-model.md §5.
 */
enum AccessScope: string
{
    case Own = 'own';
    case Assigned = 'assigned';
    case Team = 'team';
    case Section = 'section';
    case Department = 'department';
    case Division = 'division';
    case Project = 'project';
    case Organization = 'organization';

    /**
     * Rank on the organizational axis. Project is deliberately not on this axis and returns 0.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Own => 1,
            self::Assigned => 2,
            self::Team => 3,
            self::Section => 4,
            self::Department => 5,
            self::Division => 6,
            self::Organization => 8,
            self::Project => 0,
        };
    }

    public function isOrganizational(): bool
    {
        return $this !== self::Project;
    }

    /**
     * The org-unit type this scope walks up to, if it is a unit-subtree scope.
     */
    public function orgUnitType(): ?OrgUnitType
    {
        return match ($this) {
            self::Team => OrgUnitType::Team,
            self::Section => OrgUnitType::Section,
            self::Department => OrgUnitType::Department,
            self::Division => OrgUnitType::Division,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own records only',
            self::Assigned => 'Records assigned to them',
            self::Team => 'Their team',
            self::Section => 'Their section',
            self::Department => 'Their department',
            self::Division => 'Their division',
            self::Project => 'Projects they belong to',
            self::Organization => 'Entire organization',
        };
    }

    /**
     * Broadest of two scopes on the organizational axis.
     * Project never absorbs, and is never absorbed by, an organizational scope.
     */
    public static function broadest(self $a, self $b): self
    {
        if ($a === self::Project || $b === self::Project) {
            return $a === $b ? $a : ($a->rank() >= $b->rank() ? $a : $b);
        }

        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
