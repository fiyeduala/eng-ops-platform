<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Structural levels of the organization tree.
 *
 * The LABELS are configurable per organization (a company may call a Division a "Directorate"),
 * but the structural levels themselves are invariants the access engine reasons about, so they
 * live in code rather than in a table. See docs/04-access-model.md §2.
 */
enum OrgUnitType: string
{
    case Division = 'division';
    case Department = 'department';
    case Section = 'section';
    case Team = 'team';

    public function depth(): int
    {
        return match ($this) {
            self::Division => 1,
            self::Department => 2,
            self::Section => 3,
            self::Team => 4,
        };
    }

    public function defaultLabel(): string
    {
        return ucfirst($this->value);
    }

    /** Types that may contain this one. */
    public function allowedParents(): array
    {
        return match ($this) {
            self::Division => [],
            self::Department => [self::Division],
            self::Section => [self::Department],
            self::Team => [self::Section, self::Department],
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->defaultLabel();
        }

        return $out;
    }
}
