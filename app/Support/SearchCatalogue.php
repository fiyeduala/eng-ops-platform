<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * What one search box is allowed to look in.
 *
 * THERE IS NO SEARCH IMPLEMENTATION HERE, AND THAT IS THE POINT. docs/05 requires that global
 * search return exactly the same set as the index pages (§61), and the only way to be sure of that
 * is to run the index page's own query. Every register already owns a `search()` scope, already
 * argued over — Document refuses to match version CONTENT, RegisterRecord matches number and title
 * and nothing else, Memo refuses to match a body — because matching free text turns a search box
 * into an oracle that reports, through a result count alone, whether a phrase appears in records
 * the reader may list but never opened. Re-deriving searchable columns in a catalogue would have
 * quietly overruled all of that reasoning from a class that had never heard it, and the first
 * register to widen or narrow its own scope would have drifted out of parity with no test failing.
 *
 * SO THIS CLASS ONLY ANSWERS WHICH REGISTERS. A register is searchable when three things are true:
 *
 *   It is already listable — Scopeable, so each reader's visibility compiles into SQL, and keeping
 *   an activity timeline, so it is a record rather than configuration. This is ReportCatalogue's
 *   own list, unchanged, so a register a person can tabulate is a register they can search.
 *
 *   It has a `search()` scope. A register with no opinion about what matching means gets no
 *   default opinion from here.
 *
 *   RecordLinks knows a page to open it on. A hit nobody can open is not a result; it is a
 *   notification that something exists, which is the one thing §61 says search must never become.
 *
 * A module added in a later phase therefore joins the search box by being an ordinary register
 * with a page, and there is no second list here to forget (§90).
 *
 * WHAT THIS DOES NOT DO IS SPEED. One term across sixty registers is sixty small queries, each a
 * `like` with a low limit, which is honest on a cPanel database of the size this platform targets
 * and is the reason the results page offers narrowing to one register. docs/13 names typo-tolerant
 * full-text search, with the same authorization filter applied, as a VPS-migration item; it
 * replaces the `search()` scopes, and this class is unaffected when it does.
 */
final class SearchCatalogue
{
    /** @var array<string,string>|null */
    private static ?array $registerCache = null;

    /**
     * Every register one search box covers: morph alias => label.
     *
     * In ReportCatalogue's own order — alphabetical by label — so the results page groups the same
     * way the report builder's register picker lists.
     *
     * @return array<string,string>
     */
    public static function registers(): array
    {
        if (self::$registerCache !== null) {
            return self::$registerCache;
        }

        $registers = [];

        foreach (ReportCatalogue::entities() as $alias => $label) {
            if (RecordLinks::routeFor($alias) === null) {
                continue;
            }

            /** @var class-string<Model>|null $class */
            $class = ReportCatalogue::modelFor($alias);

            if ($class === null || ! method_exists($class, 'scopeSearch') || ! method_exists($class, 'scopeVisibleTo')) {
                continue;
            }

            $registers[$alias] = $label;
        }

        return self::$registerCache = $registers;
    }

    public static function searchable(string $entityType): bool
    {
        return array_key_exists($entityType, self::registers());
    }

    /** @return class-string<Model>|null */
    public static function modelFor(string $entityType): ?string
    {
        return self::searchable($entityType) ? ReportCatalogue::modelFor($entityType) : null;
    }

    public static function labelFor(string $entityType): string
    {
        return self::registers()[$entityType] ?? 'Records';
    }

    /** Forget what was discovered. Tests rebuild the schema between cases; nothing else needs it. */
    public static function flush(): void
    {
        self::$registerCache = null;
    }
}
