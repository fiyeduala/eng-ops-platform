<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * What kind of question this is.
 *
 * AN ENUM, NOT A CONFIGURABLE LIST, and that is a deliberate exception to the rule that vocabularies
 * belong in settings. Kind is not a label — it is the thing a workflow condition branches on ("route
 * site queries to the resident engineer, RFIs to the client interface manager"), it drives the
 * numbering series, and it decides which fields the form shows. A tenant renaming "Technical query"
 * to "Design query" changes a word; a tenant inventing a fifth kind changes behaviour that has no
 * behaviour written for it. The words are overridable through translation; the set is not.
 */
enum RfiKind: string
{
    /** Asked of the client or another contract party. The formal, contractual one. */
    case Rfi = 'rfi';

    /** Asked of engineering, usually by procurement, construction or a vendor. */
    case TechnicalQuery = 'technical_query';

    /** Raised from site about what was actually found there. */
    case SiteQuery = 'site_query';

    public function label(): string
    {
        return match ($this) {
            self::Rfi => 'Request for information',
            self::TechnicalQuery => 'Technical query',
            self::SiteQuery => 'Site query',
        };
    }

    /** The short form used on register rows and in numbering. */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Rfi => 'RFI',
            self::TechnicalQuery => 'TQ',
            self::SiteQuery => 'SQ',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Rfi => 'bg-sky-50 text-sky-700 ring-sky-200',
            self::TechnicalQuery => 'bg-violet-50 text-violet-700 ring-violet-200',
            self::SiteQuery => 'bg-amber-50 text-amber-800 ring-amber-200',
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
