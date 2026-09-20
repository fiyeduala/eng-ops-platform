<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ReportDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What a report may show, group by and sort on.
 *
 * THIS IS NOT A SECOND COPY OF THE KPI CATALOGUE. Three of its four lists are borrowed from it
 * outright — the registers, the filter fields, the operators — because a report and a KPI narrowing
 * the same register must narrow it identically or docs/11 §1 is broken. What is genuinely new here
 * is the COLUMN list, and the reason it is new is a real asymmetry rather than an oversight:
 *
 *   A REPORT'S COLUMNS ARE BROADER THAN A KPI'S FILTERS, ON PURPOSE. Showing somebody a document's
 *   title is not a disclosure — `visibleTo()` already decided they may see that document, and the
 *   index page shows them the same title. FILTERING on free text is different in kind: a condition
 *   the reader can retype is a way to read content back one guess at a time, and a row count is the
 *   oracle that makes it work (§61). So free text is displayable and never filterable, and those
 *   are two lists because they are two questions.
 *
 *   THE INVERSE ALSO HOLDS. A column may be filterable and unfit to print — `organization_id` is
 *   neither, but a password hash, a remember token and a stored file path are all excluded below
 *   while several of them would pass a naive "is it structured" test.
 *
 * GROUPING BORROWS THE FILTER LIST RATHER THAN THE COLUMN LIST, which is the same judgement read
 * the other way: grouping by free text both produces a page-long list of one-row groups and hands
 * back the distinct values of a column nobody was allowed to filter on. Dates are added to it,
 * because "per month" is the grouping every operational report actually wants.
 */
final class ReportCatalogue
{
    /**
     * Columns that are never printed, whatever register they appear on.
     *
     * Matched as substrings rather than whole names, because the same idea is spelled differently
     * across tables and a list of exact names is a list that goes stale silently — the next table
     * to add `api_token` would be the one nobody remembered to add here.
     */
    private const NEVER_PRINTED = [
        'password',
        'token',
        'secret',
        'signature_hash',
        'two_factor',

        // A stored path is the private disk's layout (§67). Downloads go through a route that
        // checks authorization and validates the name; a path printed into a report is that
        // control's map, handed out in advance and exportable to CSV.
        '_path',
        '_disk',
    ];

    /**
     * Every register a report may list.
     *
     * Deliberately the KPI list. "Countable" and "listable" are the same property — a register that
     * is `Scopeable` (so visibility can be applied) and carries activity (so it is a record rather
     * than configuration) — and inventing a second predicate here would let the two lists drift
     * until a company could chart something it could not tabulate.
     *
     * @return array<string,string> morph key => label
     */
    public static function entities(): array
    {
        return KpiCatalogue::countableEntities();
    }

    /** @return array<string,array<string,string>> group => (entity_type => label) */
    public static function groupedEntities(): array
    {
        return KpiCatalogue::groupedEntities();
    }

    public static function listable(string $entityType): bool
    {
        return KpiCatalogue::countable($entityType);
    }

    /** @return class-string<Model>|null */
    public static function modelFor(string $entityType): ?string
    {
        return KpiCatalogue::modelFor($entityType);
    }

    /**
     * The columns a report may print, for one register.
     *
     * Everything the table holds, less what must never be printed, less the tenant key, less the
     * soft-delete marker. Foreign keys stay in — they are shown as the thing they point at rather
     * than as a number, which is what `relationFor()` below is for.
     *
     * @return array<string,string> column => label
     */
    public static function columnsFor(string $entityType): array
    {
        $model = self::prototype($entityType);

        if ($model === null) {
            return [];
        }

        $hidden = $model->getHidden();
        $columns = [];

        foreach (KpiCatalogue::columnTypesFor($entityType) as $name => $type) {
            // `organization_id` is excluded for the reason it is excluded from filters: printing
            // the tenant key on every row of a single-tenant report is noise, and a column that
            // varies is a column that should never have been reachable (§75).
            if ($name === 'organization_id' || $name === 'deleted_at') {
                continue;
            }

            // A model that hides an attribute has already said it is not for reading. A report is
            // a reading, so the same answer applies here.
            if (in_array($name, $hidden, true)) {
                continue;
            }

            if (self::neverPrinted($name)) {
                continue;
            }

            $columns[$name] = self::label($model, $name);
        }

        return $columns;
    }

    /**
     * The fields a report may filter on — the KPI list, unchanged and un-extended.
     *
     * The comment on `KpiCatalogue::filterFieldsFor()` is the whole argument and is not repeated.
     * What matters at this call site is that nothing is added to what it returns: the temptation
     * when building a report screen is to let people filter on a title "because they can see it
     * anyway", and the answer is that seeing forty titles they are entitled to is not the same as
     * asking forty thousand yes/no questions about titles they are not.
     *
     * @return array<string,string> field => label
     */
    public static function filterFieldsFor(string $entityType): array
    {
        return KpiCatalogue::filterFieldsFor($entityType);
    }

    /**
     * The fields a report may group rows by: the filterable ones, plus dates.
     *
     * @return array<string,string> field => label
     */
    public static function groupFieldsFor(string $entityType): array
    {
        $fields = self::filterFieldsFor($entityType);

        foreach (KpiCatalogue::dateFieldsFor($entityType) as $field => $label) {
            $fields[$field] = $label;
        }

        asort($fields);

        return $fields;
    }

    /**
     * The columns a report may sort on.
     *
     * Any printable column: ordering reveals the ordering of values the reader was already shown,
     * which is not a new disclosure the way a filter is. Dotted paths are excluded because sorting
     * across a relation needs a join, and a join here would multiply rows.
     *
     * @return array<string,string> column => label
     */
    public static function sortFieldsFor(string $entityType): array
    {
        return array_filter(
            self::columnsFor($entityType),
            fn (string $column) => ! str_contains($column, '.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function offersColumn(string $entityType, string $column): bool
    {
        return array_key_exists($column, self::columnsFor($entityType));
    }

    public static function offersFilter(string $entityType, string $field): bool
    {
        return KpiCatalogue::offersFilter($entityType, $field);
    }

    public static function offersGroup(string $entityType, string $field): bool
    {
        return array_key_exists($field, self::groupFieldsFor($entityType));
    }

    public static function offersSort(string $entityType, string $column): bool
    {
        return array_key_exists($column, self::sortFieldsFor($entityType));
    }

    /**
     * The `belongsTo` behind a foreign key column, where there is one.
     *
     * Used for two things, and both matter. It lets a report print "Civil" where the column holds
     * `4`, and it tells the runner which relations to eager-load before the rows are rendered —
     * without which a 500-row report is 500 extra queries (§89), and the platform's lazy-loading
     * guard would throw rather than let it happen quietly.
     */
    public static function relationFor(string $entityType, string $column): ?string
    {
        if (! str_ends_with($column, '_id')) {
            return null;
        }

        $model = self::prototype($entityType);

        if ($model === null) {
            return null;
        }

        $relation = Str::camel(substr($column, 0, -3));

        if (! method_exists($model, $relation)) {
            return null;
        }

        // Checked rather than assumed: a method of that name might be a scope, an accessor or
        // anything else, and calling it blind inside a report render is how a screen 500s.
        try {
            return $model->{$relation}() instanceof BelongsTo ? $relation : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,string> */
    public static function operators(): array
    {
        return KpiCatalogue::operators();
    }

    /** @return array<string,string> */
    public static function directions(): array
    {
        return ReportDefinition::DIRECTIONS;
    }

    /** @return array<string,string> */
    public static function formats(): array
    {
        return ReportDefinition::FORMATS;
    }

    // ================================================================== internals

    private static function neverPrinted(string $name): bool
    {
        foreach (self::NEVER_PRINTED as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A column's heading.
     *
     * A register may name its own columns through `reportColumnLabels()` where the derivation
     * reads badly — "Org unit" for what a company calls a section, say. Nothing is required to.
     */
    private static function label(Model $model, string $column): string
    {
        if (method_exists($model, 'reportColumnLabels')) {
            $declared = $model->reportColumnLabels();

            if (isset($declared[$column]) && is_string($declared[$column])) {
                return $declared[$column];
            }
        }

        // `_id` is dropped because the column is printed as the thing it points at, and `_at`
        // because "Created" reads better in a heading than "Created at".
        return Str::headline(str_replace(['_id', '_at'], '', $column));
    }

    private static function prototype(string $entityType): ?Model
    {
        $class = KpiCatalogue::modelFor($entityType);

        return $class === null ? null : new $class;
    }
}
