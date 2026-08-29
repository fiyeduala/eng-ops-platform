<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Gate 5 of the access model.
 *
 * This is the enum that makes the section 14 requirement true: a Finance record marked
 * CONFIDENTIAL does not become visible merely because the viewer belongs to the project.
 */
enum Confidentiality: string
{
    /** Anyone in the organization holding the base view permission. */
    case Public = 'public';

    /** Default. Normal scope rules apply. */
    case Internal = 'internal';

    /** Owning org-unit subtree plus explicit ACL. Project membership is NOT sufficient. */
    case Restricted = 'restricted';

    /** Explicit ACL only, plus holders of <module>.view_confidential. */
    case Confidential = 'confidential';

    public function rank(): int
    {
        return match ($this) {
            self::Public => 1,
            self::Internal => 2,
            self::Restricted => 3,
            self::Confidential => 4,
        };
    }

    /** Levels above internal are not reachable through project membership alone. */
    public function reachableByProjectMembership(): bool
    {
        return $this->rank() <= self::Internal->rank();
    }

    /** Levels that require the owning org unit or an explicit ACL entry. */
    public function requiresOwningUnitOrAcl(): bool
    {
        return $this === self::Restricted;
    }

    public function requiresAclOnly(): bool
    {
        return $this === self::Confidential;
    }

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public (organization-wide)',
            self::Internal => 'Internal',
            self::Restricted => 'Restricted (owning department only)',
            self::Confidential => 'Confidential (named recipients only)',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Public => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Internal => 'bg-sky-50 text-sky-700 ring-sky-200',
            self::Restricted => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Confidential => 'bg-rose-50 text-rose-700 ring-rose-200',
        };
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
