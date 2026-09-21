<?php

declare(strict_types=1);

namespace App\Engines\Search;

use App\Models\User;
use App\Support\SearchCatalogue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * One box, every register, each one narrowed to the person typing (§61).
 *
 * THIS IS NOT A SIXTH ENGINE. It owns no rule of its own and decides nothing: which registers are
 * covered is SearchCatalogue's answer, what a term matches is each register's own `search()` scope
 * — the one its index page already calls — and who may see a row is the access engine's
 * `visibleTo()`, compiled into the same SQL it compiles everywhere else. docs/05 requires that
 * global search return exactly the same set as the index pages, and the way to be certain of that
 * is not to test two implementations against each other; it is to have one.
 *
 * SO THE INTERESTING CODE HERE IS ABOUT WHAT IS *NOT* ASKED. A register the reader holds no view
 * permission for is skipped before any query runs, so the results page has no heading for it —
 * absent rather than empty, because "Purchase orders (0)" tells somebody there is a purchasing
 * module, how it is named, and that their term found nothing in it, which is three answers they
 * were not entitled to (§61). And no register is counted: each is asked for one row more than it
 * will show, which answers "is there more" without a COUNT per register per request.
 *
 * COST, HONESTLY. A term across every register a director can read is roughly sixty small
 * `like`-with-limit queries. That is acceptable on the databases this platform is deployed to and
 * it is why the results page shows a handful per register and offers narrowing to one. docs/13
 * names typo-tolerant full-text search, with the same authorization filter applied, as the
 * VPS-migration upgrade; when it lands it replaces the `search()` scopes underneath this class and
 * nothing here changes.
 *
 * WHY THE TERM HAS A MINIMUM. A single character matches most of a database, which is not a search
 * result and is an expensive way to say so.
 */
class RecordSearch
{
    /** Shorter than this is not a search term. */
    public const MIN_TERM = 2;

    /** Longer than this is somebody testing what the box does. */
    public const MAX_TERM = 120;

    /** How many hits one register contributes to the overview. */
    public const PER_REGISTER = 5;

    /** How many a single narrowed register lists per page. */
    public const PER_PAGE = 25;

    /**
     * Relations a record's own name might be built from.
     *
     * A leave request calls itself "LR-0042 — Ngozi Eze, 3–7 March", which reads the employee it
     * belongs to. Eager-loading is not optional here: lazy loading is prevented outside production
     * (§89), so a name assembled from an unloaded relation is an exception on the results page
     * rather than a slow query. Every name below is checked by reflection against the register
     * before it is loaded, so a hint a model has never heard of costs nothing and breaks nothing.
     *
     * @var array<int,string>
     */
    private const RELATION_HINTS = [
        'employee', 'user', 'project', 'orgUnit', 'form', 'cycle', 'course',
        'document', 'asset', 'vendor', 'client', 'leaveType',
    ];

    /** @var array<class-string,array<int,string>> */
    private static array $eagerCache = [];

    /**
     * Every register's answer to one term, in the catalogue's order.
     *
     * Registers with nothing to say are dropped rather than returned empty, so the page is a list
     * of what was found and never a directory of what exists.
     *
     * @return array<int,SearchGroup>
     */
    public function across(?string $term, ?User $viewer): array
    {
        $term = self::normalise($term);

        if ($term === null || $viewer === null) {
            return [];
        }

        $groups = [];

        foreach (SearchCatalogue::registers() as $entityType => $label) {
            $query = $this->queryFor($entityType, $term, $viewer);

            if ($query === null) {
                continue;
            }

            // One more than will be shown. Its presence is the whole "there is more" signal, and
            // it is discarded rather than rendered.
            $records = $query->limit(self::PER_REGISTER + 1)->get();

            if ($records->isEmpty()) {
                continue;
            }

            $groups[] = new SearchGroup(
                entityType: $entityType,
                label: $label,
                records: $records->take(self::PER_REGISTER),
                more: $records->count() > self::PER_REGISTER,
            );
        }

        return $groups;
    }

    /**
     * One register's answer to one term, paginated.
     *
     * Null where `across()` would simply have skipped the register: an entity type that is not
     * searchable, or one whose read permission this person does not hold. The caller turns that
     * into a 404 rather than an empty page, for the reason the drill-down does — an empty result
     * and a refused one must not look alike (§84, §94).
     *
     * @return LengthAwarePaginator<int,Model>|null
     */
    public function within(string $entityType, ?string $term, ?User $viewer): ?LengthAwarePaginator
    {
        $term = self::normalise($term);

        if ($term === null || $viewer === null) {
            return null;
        }

        $query = $this->queryFor($entityType, $term, $viewer);

        return $query?->paginate(self::PER_PAGE)->withQueryString();
    }

    /** Is this string worth running a search for at all? */
    public static function usable(?string $term): bool
    {
        return self::normalise($term) !== null;
    }

    public static function normalise(?string $term): ?string
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_TERM) {
            return null;
        }

        return mb_substr($term, 0, self::MAX_TERM);
    }

    /**
     * The register's own index-page query, narrowed to this reader and this term.
     *
     * @return Builder<Model>|null
     */
    private function queryFor(string $entityType, string $term, User $viewer): ?Builder
    {
        /** @var class-string<Model>|null $class */
        $class = SearchCatalogue::modelFor($entityType);

        if ($class === null) {
            return null;
        }

        $permission = $class::defaultViewPermission();

        // The cheap half of the question, asked first and in memory. `visibleTo` would return
        // nothing for somebody holding no grant at all, but "nothing" and "not offered" are
        // different answers and only the second one is honest about why.
        if (! $viewer->hasPermission($permission)) {
            return null;
        }

        $prototype = new $class;

        $query = $class::query()
            ->visibleTo($viewer, $permission)
            ->search($term)
            ->orderByDesc($prototype->qualifyColumn($prototype->getKeyName()));

        $eager = $this->eagerLoadsFor($class);

        return $eager === [] ? $query : $query->with($eager);
    }

    /**
     * Which of the hinted relations this register actually has.
     *
     * Confirmed by reflection on the return type rather than by `method_exists` alone: a model
     * with a `form()` helper that returns a string would otherwise be handed to `with()` and throw
     * at query time. Worked out once per class per request.
     *
     * @param  class-string<Model>  $class
     * @return array<int,string>
     */
    private function eagerLoadsFor(string $class): array
    {
        if (isset(self::$eagerCache[$class])) {
            return self::$eagerCache[$class];
        }

        $loads = [];

        foreach (self::RELATION_HINTS as $name) {
            if (! method_exists($class, $name)) {
                continue;
            }

            $type = (new ReflectionMethod($class, $name))->getReturnType();

            if ($type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true)) {
                $loads[] = $name;
            }
        }

        return self::$eagerCache[$class] = $loads;
    }
}
