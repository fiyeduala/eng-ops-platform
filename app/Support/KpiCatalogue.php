<?php

declare(strict_types=1);

namespace App\Support;

use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Models\KpiDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What a KPI is allowed to be built out of.
 *
 * THIS CLASS IS THE WHITELIST docs/11 §5 REQUIRES. "`source` is a whitelisted entity + column
 * descriptor, never raw SQL — administrators compose KPIs from safe building blocks, so a KPI can
 * never become an injection vector." Every register name, every filterable field, every column that
 * can be summed and every date a window can be measured on passes through here, on the way in when
 * a definition is saved and again on the way out when it is evaluated. Nothing from a request ever
 * reaches a query builder without having been looked up in one of these lists first (§84).
 *
 * THE SECOND CHECK IS THE ONE THAT MATTERS. A KPI is long-lived configuration, and the register it
 * names can be renamed, narrowed or retired years after somebody defined it. Validating only on
 * save would leave a definition holding a column name that was legal when it was typed; asking
 * again at evaluation means such a KPI returns nothing rather than reaching the database with it.
 *
 * WHAT COUNTS AS COUNTABLE, and why it is discovered rather than listed. A register is offered here
 * when it is Scopeable AND keeps an activity timeline. Both halves carry weight:
 *
 *   Scopeable is the §61 requirement. A total is only safe to show because it can be narrowed to
 *   what the person asking could have counted by hand; a model with no visibility scope has no
 *   such narrowing, so there is no honest number to show them and it is not offered at all.
 *
 *   An activity timeline is what separates a RECORD from CONFIGURATION. A deliverable, an incident
 *   and a purchase order have lives — they are raised, worked on and closed, and counting them
 *   says something about the company. A status vocabulary, a numbering scheme and a workflow stage
 *   do not; they are the shape of the company's tooling, and a chart of how many of them exist is
 *   not an indicator of anything.
 *
 * A module added in a later phase therefore appears in the KPI builder by being an ordinary
 * register, with no list here to forget (§90) — and a register that wants in and is missing joins
 * by keeping a timeline, which it ought to be doing anyway.
 */
final class KpiCatalogue
{
    /**
     * Columns that are numbers to the database and are never a measure.
     *
     * Keys, sort orders and version counters are all integers, and every one of them would produce
     * a figure — "total of revision number" — that is arithmetic rather than information. Offering
     * them is how an administrator builds a KPI that is not wrong so much as meaningless, which is
     * worse: a wrong number gets questioned.
     *
     * @var array<int,string>
     */
    private const NEVER_A_MEASURE = [
        'id', 'sort', 'display_order', 'position', 'sequence', 'revision', 'revision_number',
        'version', 'version_number', 'year', 'month', 'day', 'week', 'quarter',
        'latitude', 'longitude', 'file_size',
    ];

    /**
     * Timestamps a window is never measured on.
     *
     * `updated_at` moves whenever anybody touches anything, so a KPI windowed on it counts editing
     * activity while claiming to count work. `deleted_at` counts deletions, which is a question for
     * the audit trail rather than for a dashboard.
     *
     * @var array<int,string>
     */
    private const NEVER_A_WINDOW = ['updated_at', 'deleted_at'];

    /** Database types that hold a quantity worth adding up. `tinyint` is excluded: it is a flag. */
    private const NUMERIC_TYPES = [
        'int', 'integer', 'bigint', 'mediumint', 'smallint',
        'decimal', 'numeric', 'float', 'double', 'real',
    ];

    private const DATE_TYPES = ['date', 'datetime', 'timestamp'];

    /** @var array<string,array<string,string>> */
    private static array $columnCache = [];

    /**
     * Every register a KPI may count: morph key => label.
     *
     * @return array<string,string>
     */
    public static function countableEntities(): array
    {
        $entities = [];

        foreach (Relation::morphMap() as $key => $class) {
            if (! class_exists($class) || ! self::isCountable($class)) {
                continue;
            }

            // The same naming ladder the expiry catalogue uses, and for the same reason: a
            // register that has already told one configuration screen what it is called is not
            // asked to say so again, because two screens deriving a name independently is how an
            // administrator ends up wondering whether "Rfis" and "RFIs" are the same register.
            $entities[$key] = match (true) {
                method_exists($class, 'kpiEntityLabel') => $class::kpiEntityLabel(),
                method_exists($class, 'workflowEntityLabel') => $class::workflowEntityLabel(),
                default => str(class_basename($class))->headline()->plural()->toString(),
            };
        }

        asort($entities);

        return $entities;
    }

    /**
     * The same list, grouped the way the permission screen groups the platform.
     *
     * Sixty-odd registers in one flat select is a list nobody reads to the end. The grouping is
     * borrowed rather than invented so that "where do I find incidents" has the same answer in the
     * KPI builder as it does everywhere else.
     *
     * @return array<string,array<string,string>> group => (entity_type => label)
     */
    public static function groupedEntities(): array
    {
        $grouped = [];

        foreach (self::countableEntities() as $key => $label) {
            $class = Relation::getMorphedModel($key);
            $module = $class !== null && method_exists($class, 'permissionModule')
                ? $class::permissionModule()
                : $key;

            $grouped[PermissionCatalogue::groupFor($module) ?? 'Other'][$key] = $label;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * The fields a KPI filter may test, for one register.
     *
     * THE WORKFLOW BUILDER'S LIST, DELIBERATELY REUSED. That list is already the answer to "which
     * columns is it safe to let an administrator branch on": structured values only, never a title,
     * a description or a comment, because a condition that can test free text is a way to read it
     * back out one guess at a time (§61). A KPI filter is the same question asked of a whole table
     * at once — "how many documents contain this phrase" is that attack with a progress bar — so it
     * gets the same list rather than a second one that would drift away from it.
     *
     * NOT EVERY COUNTABLE REGISTER HAS ONE. Projects, tasks and meetings are not routed for
     * approval, so nobody ever had to write their condition whitelist — and returning nothing for
     * them would leave the three registers most companies measure first able to answer only "how
     * many are there in total". Those fall through to a derived list, built to the same rule the
     * written ones follow rather than to a looser one: identifiers, flags and enumerations only.
     *
     * @return array<string,string> field => label
     */
    public static function filterFieldsFor(string $entityType): array
    {
        $model = self::prototype($entityType);

        if ($model === null) {
            return [];
        }

        $declared = method_exists($model, 'workflowConditionFields')
            ? $model->workflowConditionFields()
            : [];

        $fields = [];

        foreach ($declared === [] ? self::derivedFilterFields($model) : $declared as $field) {
            $fields[$field] = self::label($field);
        }

        return $fields;
    }

    /**
     * The structured columns of a register that never declared its own list.
     *
     * THE RULE IS THE ONE RegisterRecord WRITES DOWN, applied by hand instead of by hand-written
     * list: "structured values only — a kind, a severity, a review code, an origin. Never a title,
     * description, question, answer or comment." So this admits exactly three shapes and nothing
     * else: a foreign key, a boolean, and a column cast to an enumeration. A string column is never
     * admitted however harmless it looks, because a filter that can test free text is a way to read
     * it back out one guess at a time — and at KPI scale that is a binary search with a counter on
     * it (§61).
     *
     * `organization_id` is left out although it is a foreign key: filtering on the tenant is either
     * a no-op or an attempt to leave it, and neither belongs on a form (§75).
     *
     * @return array<int,string>
     */
    private static function derivedFilterFields(Model $model): array
    {
        $casts = $model->getCasts();
        $fields = [];

        foreach (self::columns($model) as $name => $type) {
            if ($name === 'id' || $name === 'organization_id') {
                continue;
            }

            $cast = $casts[$name] ?? null;

            // A foreign key is an INTEGER ending in `_id`, and the type check is not pedantry —
            // `national_id` and `tax_id` end in `_id` too and are neither keys nor structured.
            // They are personal identifiers held as strings, and a filter over one is a way to ask
            // "does this company employ the holder of this number", answered a guess at a time by
            // a counter on a dashboard. That is the precise attack §61 is about, so the shape of
            // the column decides rather than the spelling of its name.
            $structured = (str_ends_with($name, '_id') && in_array($type, self::NUMERIC_TYPES, true))
                || $cast === 'boolean'
                || $cast === 'bool'
                || (is_string($cast) && enum_exists($cast));

            if ($structured) {
                $fields[] = $name;
            }
        }

        sort($fields);

        return $fields;
    }

    /**
     * The columns that can be summed or averaged, for one register.
     *
     * Discovered from the table's own types and then narrowed hard: keys and foreign keys are
     * excluded because adding up identifiers is nonsense, `_by` columns because they are people
     * rather than quantities, booleans because a sum of flags is a count wearing a disguise, and
     * the bookkeeping columns above because "average revision number" is arithmetic that looks
     * like information.
     *
     * A register may say the list itself through `kpiMeasureFields()` where the derivation gets it
     * wrong — a column that is technically numeric and genuinely private, or one this cannot name.
     *
     * @return array<string,string> column => label
     */
    public static function measureFieldsFor(string $entityType): array
    {
        $model = self::prototype($entityType);

        if ($model === null) {
            return [];
        }

        if (method_exists($model, 'kpiMeasureFields')) {
            $declared = [];

            foreach ($model->kpiMeasureFields() as $field) {
                $declared[$field] = self::label($field);
            }

            return $declared;
        }

        $casts = $model->getCasts();
        $fields = [];

        foreach (self::columns($model) as $name => $type) {
            if (! in_array($type, self::NUMERIC_TYPES, true)) {
                continue;
            }

            if (in_array($name, self::NEVER_A_MEASURE, true)) {
                continue;
            }

            // `_by` and `_to` are people wearing an integer: `created_by`, `performed_by`,
            // `handover_to`. The database cannot tell them from a quantity, and an average of
            // user ids is the kind of figure that appears on a dashboard and is never questioned
            // because nobody can work out what it is claiming.
            if ($name === 'id' || str_ends_with($name, '_id')
                || str_ends_with($name, '_by') || str_ends_with($name, '_to')) {
                continue;
            }

            // A column cast to boolean or to an enum is a classification however the database
            // chose to store it, and neither is something to add up.
            $cast = $casts[$name] ?? null;

            if ($cast === 'boolean' || $cast === 'bool' || (is_string($cast) && enum_exists($cast))) {
                continue;
            }

            $fields[$name] = self::label($name);
        }

        ksort($fields);

        return $fields;
    }

    /**
     * The dates a period window may be measured on, for one register.
     *
     * Every date and datetime the table holds, less the two that answer the wrong question. This is
     * wider than the expiry catalogue's list on purpose: that one asks "which dates is it worth
     * warning about", which is a judgement each register makes, while this asks "when did this
     * happen", which every date column is a legitimate answer to. "Incidents by date of incident"
     * and "incidents by date reported" are different KPIs and a company may want both.
     *
     * @return array<string,string> column => label
     */
    public static function dateFieldsFor(string $entityType): array
    {
        $model = self::prototype($entityType);

        if ($model === null) {
            return [];
        }

        $fields = [];

        foreach (self::columns($model) as $name => $type) {
            if (! in_array($type, self::DATE_TYPES, true)) {
                continue;
            }

            if (in_array($name, self::NEVER_A_WINDOW, true)) {
                continue;
            }

            $fields[$name] = self::label($name);
        }

        ksort($fields);

        return $fields;
    }

    // ================================================================== the questions asked of it

    public static function countable(string $entityType): bool
    {
        $class = Relation::getMorphedModel($entityType);

        return $class !== null && class_exists($class) && self::isCountable($class);
    }

    public static function offersFilter(string $entityType, string $field): bool
    {
        return array_key_exists($field, self::filterFieldsFor($entityType));
    }

    public static function offersMeasure(string $entityType, string $field): bool
    {
        return array_key_exists($field, self::measureFieldsFor($entityType));
    }

    public static function offersDate(string $entityType, string $field): bool
    {
        return array_key_exists($field, self::dateFieldsFor($entityType));
    }

    /** The class behind a countable register, or null where the key names nothing countable. */
    public static function modelFor(string $entityType): ?string
    {
        $class = Relation::getMorphedModel($entityType);

        if ($class === null || ! class_exists($class) || ! self::isCountable($class)) {
            return null;
        }

        return $class;
    }

    // ================================================================== columns a scope binds to

    /**
     * The column that says which department a record belongs to, where it has one.
     *
     * Borrowed from the access hooks rather than guessed, so a register whose department lives
     * somewhere unusual is scoped correctly here without saying so twice.
     */
    public static function orgUnitColumnFor(string $entityType): ?string
    {
        $model = self::prototype($entityType);

        if ($model === null || ! method_exists($model, 'accessOrgUnitColumn')) {
            return null;
        }

        $column = $model->accessOrgUnitColumn();

        return $column !== null && isset(self::columns($model)[$column]) ? $column : null;
    }

    public static function projectColumnFor(string $entityType): ?string
    {
        $model = self::prototype($entityType);

        if ($model === null || ! method_exists($model, 'accessProjectColumn')) {
            return null;
        }

        $column = $model->accessProjectColumn();

        return $column !== null && isset(self::columns($model)[$column]) ? $column : null;
    }

    /**
     * The column that says which person a record is about.
     *
     * `employee_id` where the register has one — a leave request, a timesheet, an appraisal are all
     * ABOUT somebody. Otherwise whoever raised it, which is the nearest honest answer for a
     * register that is about a thing rather than a person.
     */
    public static function employeeColumnFor(string $entityType): ?string
    {
        $model = self::prototype($entityType);

        if ($model === null) {
            return null;
        }

        $columns = self::columns($model);

        if (isset($columns['employee_id'])) {
            return 'employee_id';
        }

        if (! method_exists($model, 'accessOwnerColumns')) {
            return null;
        }

        foreach ($model->accessOwnerColumns() as $column) {
            if (isset($columns[$column])) {
                return $column;
            }
        }

        return null;
    }

    // ================================================================== lists for the builder

    /** @return array<string,string> */
    public static function aggregates(): array
    {
        return KpiDefinition::AGGREGATES;
    }

    /** @return array<string,string> */
    public static function operators(): array
    {
        return KpiDefinition::FILTER_OPERATORS;
    }

    /** @return array<string,string> */
    public static function periods(): array
    {
        return KpiDefinition::PERIODS;
    }

    /** @return array<string,string> */
    public static function scopes(): array
    {
        return KpiDefinition::SCOPES;
    }

    /** @return array<string,string> */
    public static function directions(): array
    {
        return KpiDefinition::DIRECTIONS;
    }

    // ================================================================== internals

    /** @param  class-string  $class */
    private static function isCountable(string $class): bool
    {
        $uses = class_uses_recursive($class);

        return in_array(Scopeable::class, $uses, true)
            && in_array(HasActivity::class, $uses, true);
    }

    private static function prototype(string $entityType): ?Model
    {
        $class = self::modelFor($entityType);

        return $class === null ? null : new $class;
    }

    /**
     * One register's columns and their types, for a catalogue that needs to reason about shape.
     *
     * Exposed so the report catalogue can read a table without opening a second schema connection
     * and keeping a second cache beside this one — the two would then disagree about a table the
     * moment a migration ran mid-request, and in tests, which rebuild the schema between cases,
     * `flush()` would clear only one of them.
     *
     * @return array<string,string> column => type name
     */
    public static function columnTypesFor(string $entityType): array
    {
        $model = self::prototype($entityType);

        return $model === null ? [] : self::columns($model);
    }

    /**
     * This table's columns and their types, read once per table per request.
     *
     * @return array<string,string> column => type name
     */
    private static function columns(Model $model): array
    {
        $table = $model->getTable();

        if (! isset(self::$columnCache[$table])) {
            $types = [];

            foreach ($model->getConnection()->getSchemaBuilder()->getColumns($table) as $column) {
                $types[(string) $column['name']] = mb_strtolower((string) ($column['type_name'] ?? ''));
            }

            self::$columnCache[$table] = $types;
        }

        return self::$columnCache[$table];
    }

    /** Forget the cached column types. Tests rebuild the schema between cases; nothing else needs it. */
    public static function flush(): void
    {
        self::$columnCache = [];
    }

    private static function label(string $field): string
    {
        return str(str_replace(['.', '_id', '_at', '_'], [' → ', '', '', ' '], $field))
            ->headline()
            ->toString();
    }
}
