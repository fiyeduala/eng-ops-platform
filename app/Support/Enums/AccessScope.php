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
     * Broadest of two scopes on the SAME axis.
     *
     * Comparing across axes is refused rather than answered, because there is no honest answer.
     * `project` and `department` are not two points on one line: neither contains the other, and
     * whichever this returned would be wrong — the narrower silently deletes reach the user was
     * granted, the broader silently invents reach they were not. The correct handling of two
     * axes is to keep both, which is what ScopeSet does and what RoleTemplates::expand() does by
     * partitioning on isOrganizational() before it ever gets here.
     *
     * So both callers already satisfy this precondition. It throws anyway: the next caller is
     * the one this protects, and a loud failure in a role template is a bug report, while a
     * quiet collapse is a permissions incident nobody notices for a year.
     *
     * @throws \InvalidArgumentException when one scope is `project` and the other is not
     */
    public static function broadest(self $a, self $b): self
    {
        if ($a->isOrganizational() !== $b->isOrganizational()) {
            throw new \InvalidArgumentException(
                "Cannot take the broadest of {$a->value} and {$b->value}: they are on different axes, "
                .'and neither contains the other. Keep both — see docs/04-access-model.md section 5.'
            );
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
