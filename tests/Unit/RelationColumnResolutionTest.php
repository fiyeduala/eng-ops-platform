<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A column named inside a whereHas() closure that the related table does not have.
 *
 * THE DEFECT HAS NO ERROR MESSAGE, WHICH IS WHY IT NEEDS A TEST THAT READS SOURCE CODE. SQL resolves
 * a column inside a correlated subquery against the INNER table first and then, if it is not there,
 * against the ENCLOSING one. So this:
 *
 *     Role::whereHas('permissions', fn ($q) => $q->where('name', 'approval.reassign'))
 *
 * is not a mistake the database will tell you about. `permissions` has no `name` column — the
 * catalogue column is `key` — so `name` binds to `roles.name` instead, and the query asks which
 * roles are TITLED "approval.reassign". None are. It returns an empty set, every time, forever,
 * without an exception, a warning or a log line. The feature built on it is off and looks fine.
 *
 * That exact query is in EscalationService and in ExecutiveBriefing, both written with
 * `permissions.key` QUALIFIED, and both carrying a comment saying why. EscalationLadderTest proves
 * the escalation one behaves at runtime. This file is the general case: every whereHas closure in
 * the application, checked against the migrations, so the next one written is caught before it is
 * shipped rather than after somebody notices a report that has been empty for a month.
 *
 * IT IS STATIC ON PURPOSE. A runtime test can only catch the branches somebody thought to exercise,
 * and the whole hazard here is branches nobody exercises. Reading the source catches the ones
 * nobody has written a fixture for — which, for a query that silently returns nothing, is most of
 * them.
 *
 * THREE CATEGORIES ARE REPORTED DIFFERENTLY, and all three fail:
 *
 *   · SILENT MISRESOLVE — absent from the inner table and PRESENT on the outer one. The live
 *     defect: valid SQL, wrong question, no symptom.
 *   · WOULD ERROR AT RUNTIME — absent from both. Still a bug, but a loud one; "it throws instead
 *     of lying" is not a defence.
 *   · ABSENT; OUTER UNKNOWN — absent from the inner table, and the parser could not work out which
 *     table the closure is nested inside, so it cannot say which of the two it is. Reported as its
 *     own category rather than guessed at, because a failure message is read by somebody about to
 *     act on it and a confident wrong word costs more than an honest one.
 *
 * WHAT IT CANNOT SEE, and why the floor below exists: a relation is tied to its model by reading
 * `return $this->belongsTo(Foo::class)` out of the model source. A relation defined dynamically, or
 * on a class this parser does not recognise, is UNRESOLVED and skipped. Skipping is safe but silent
 * — the same failure shape as the bug being hunted — so the number of call sites actually checked
 * is asserted. A refactor that quietly stopped this parser from resolving anything would otherwise
 * turn the file green and useless in the same commit.
 */
final class RelationColumnResolutionTest extends TestCase
{
    /**
     * Measured at 52 resolved sites when this was written. It is a floor, not a target: it exists
     * so a parser that stops resolving relations fails instead of passing on nothing.
     */
    private const MINIMUM_RESOLVED_SITES = 45;

    /**
     * Sites the parser cannot tie to a model, listed rather than counted.
     *
     * Each one is a place this test is NOT protecting, so the list is short and explicit. An entry
     * stops being acceptable the moment it is easy to resolve — this is a record of the parser's
     * blind spots, not a suppression file.
     *
     * `DocumentController::workflowInstances` is a morphMany declared on the HasWorkflow trait
     * rather than on Document, so there is no `return $this->morphMany(...)` in the model source to
     * read. The column it names (`status`) is on `workflow_instances`, checked by eye.
     */
    private const UNRESOLVED_SITES = [
        'app/Http/Controllers/DocumentController.php  workflowInstances  (status)',
    ];

    #[Test]
    public function no_where_has_closure_names_a_column_the_related_table_does_not_have(): void
    {
        [$findings] = $this->scan();

        $report = array_map(
            fn (array $f) => $f['severity'].'  '.$f['where'].'  '.$f['relation']
                .'  column `'.$f['column'].'` is absent'
                .($f['outer'] !== null ? ' and resolves instead to `'.$f['outer'].'.'.$f['column'].'`' : ''),
            $findings,
        );

        sort($report);

        $this->assertSame([], $report, count($report).' whereHas closure(s) name a column the '
            ."related table does not have:\n  ".implode("\n  ", $report)
            ."\n\nQualify the column (`permissions.key`, not `key`) and check it exists. An "
            .'unqualified column that is missing from the inner table binds to the outer one and '
            .'the query returns nothing without erroring.');
    }

    /**
     * The floor. See the class docblock: without it, a parser that resolves nothing passes.
     */
    #[Test]
    public function the_scan_still_resolves_enough_call_sites_to_mean_anything(): void
    {
        [, $resolved] = $this->scan();

        $this->assertGreaterThanOrEqual(self::MINIMUM_RESOLVED_SITES, $resolved,
            'only '.$resolved.' whereHas call sites could be tied to a model, down from the '
            .self::MINIMUM_RESOLVED_SITES.' this test was written against. The scan has stopped '
            .'looking at most of the application, which makes it pass for the wrong reason.');
    }

    /**
     * The blind spots are the declared ones and no others.
     *
     * A new unresolved site is not a failure of the application — it is a failure of this test to
     * cover it, and the difference matters enough to be stated in the failure message rather than
     * left for somebody to work out.
     */
    #[Test]
    public function the_parser_has_only_the_blind_spots_it_admits_to(): void
    {
        [, , $unresolved] = $this->scan();

        sort($unresolved);

        $declared = self::UNRESOLVED_SITES;
        sort($declared);

        $this->assertSame($declared, $unresolved,
            "the set of whereHas sites this scan cannot resolve has changed.\n\nA NEW entry means a "
            ."relation was added that this parser cannot tie to a model, so that call site is no \n"
            ."longer being checked — verify its columns by hand and add it to UNRESOLVED_SITES with \n"
            .'the reason. A REMOVED entry means one became resolvable: delete it from the list.');
    }

    // ================================================================== the scan

    /**
     * @return array{0: array<int,array{where:string,relation:string,column:string,severity:string,outer:?string}>, 1: int, 2: array<int,string>}
     */
    private function scan(): array
    {
        $columns = $this->tableColumns();
        [$modelTable, $relations] = $this->models();

        $findings = [];
        $unresolved = [];
        $resolved = 0;

        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match('/\n(?:final )?(?:abstract )?class ([A-Za-z0-9_]+)/', $source, $match)) {
                continue;
            }

            $outerClass = $match[1];

            // A controller or service names some model's relation. FooController queries Foo often
            // enough that stripping the suffix is a better first guess than searching every model.
            $outerModel = isset($relations[$outerClass]) ? $outerClass : null;

            if ($outerModel === null) {
                $guess = (string) preg_replace('/(Controller|Service|Listener|Engine|Policy)$/', '', $outerClass);

                if ($guess !== '' && isset($relations[$guess])) {
                    $outerModel = $guess;
                }
            }

            preg_match_all(
                '/where(?:Has|DoesntHave)\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/',
                $source,
                $hits,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($hits as $hit) {
                $relation = $hit[1][0];
                $line = substr_count(substr($source, 0, $hit[0][1]), "\n") + 1;

                $arguments = $this->balancedArgs($source, $hit[0][1]);

                if ($arguments === null) {
                    continue;
                }

                // Drop the relation name so only the closure is searched.
                $closure = (string) preg_replace('/^\s*[\'"][a-zA-Z0-9_]+[\'"]\s*,?/', '', $arguments, 1);

                // Qualified columns contain a dot and cannot misresolve, so the character class
                // excludes them: they are the fix, not the defect.
                preg_match_all(
                    '/->(?:or)?[wW]here(?:Not)?(?:In|Null|NotNull|Column|Between)?\(\s*[\'"]([a-z0-9_]+)[\'"]/',
                    $closure,
                    $namedColumns,
                );

                if ($namedColumns[1] === []) {
                    continue;
                }

                $relatedClass = $outerModel !== null ? ($relations[$outerModel][$relation] ?? null) : null;

                if ($relatedClass === null) {
                    // Every model declaring a relation of this name. Accepted only if unambiguous:
                    // guessing between two candidates would check the wrong table and report a
                    // clean result, which is worse than reporting nothing.
                    $candidates = [];

                    foreach ($relations as $map) {
                        if (isset($map[$relation])) {
                            $candidates[$map[$relation]] = true;
                        }
                    }

                    if (count($candidates) === 1) {
                        $relatedClass = array_key_first($candidates);
                    }
                }

                $innerTable = $relatedClass === null ? null : ($modelTable[$relatedClass] ?? null);

                if ($innerTable === null || ! isset($columns[$innerTable])) {
                    $unresolved[] = $this->shortPath($file).'  '.$relation
                        .'  ('.implode(', ', array_unique($namedColumns[1])).')';

                    continue;
                }

                $resolved++;

                $outerTable = $outerModel !== null ? ($modelTable[$outerModel] ?? null) : null;

                foreach (array_unique($namedColumns[1]) as $column) {
                    if (isset($columns[$innerTable][$column])) {
                        continue;
                    }

                    // Three states, not two. Knowing the outer table is what separates 'this
                    // lies' from 'this throws'; NOT knowing it is a third answer, and printing one
                    // of the first two anyway would put a confident wrong word in a failure message
                    // somebody is about to act on.
                    $bindsToOuter = $outerTable !== null && isset($columns[$outerTable][$column]);

                    $severity = match (true) {
                        $bindsToOuter => 'SILENT MISRESOLVE     ',
                        $outerTable !== null => 'would error at runtime',
                        default => 'absent; outer unknown  ',
                    };

                    $findings[] = [
                        'where' => $this->shortPath($file).':'.$line,
                        'relation' => $relation.' -> '.$innerTable,
                        'column' => $column,
                        'severity' => $severity,
                        'outer' => $bindsToOuter ? $outerTable : null,
                    ];
                }
            }
        }

        return [$findings, $resolved, array_values(array_unique($unresolved))];
    }

    /**
     * table => [column => true], read from the migrations.
     *
     * Both `create()` and `table()`, so a column added by a later migration counts. Reading the
     * migrations rather than the live schema keeps this a unit test — it needs no database, and it
     * therefore also catches a column renamed in a migration that has not been run yet.
     *
     * @return array<string,array<string,true>>
     */
    private function tableColumns(): array
    {
        $columns = [];

        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all(
                '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*function[^{]*\{(.*?)\n        \}\);/s',
                $source,
                $blocks,
                PREG_SET_ORDER,
            );

            foreach ($blocks as [, $table, $body]) {
                $columns[$table] ??= ['id' => true];

                preg_match_all('/\$table->[a-zA-Z]+\(\s*[\'"]([a-z0-9_]+)[\'"]/', $body, $found);

                foreach ($found[1] as $column) {
                    $columns[$table][$column] = true;
                }

                if (str_contains($body, '->timestamps()')) {
                    $columns[$table]['created_at'] = true;
                    $columns[$table]['updated_at'] = true;
                }

                if (str_contains($body, '->softDeletes()')) {
                    $columns[$table]['deleted_at'] = true;
                }
            }
        }

        return $columns;
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,array<string,string>>}
     */
    private function models(): array
    {
        $modelTable = [];
        $relations = [];

        foreach ($this->phpFilesIn(base_path('app/Models')) as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match('/\n(?:final )?(?:abstract )?class ([A-Za-z0-9_]+)/', $source, $match)) {
                continue;
            }

            $class = $match[1];

            $modelTable[$class] = preg_match('/protected \$table\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $explicit)
                ? $explicit[1]
                : $this->pluralise($this->snake($class));

            preg_match_all(
                '/public function ([a-zA-Z0-9_]+)\(\)[^{]*\{\s*return \$this->'
                .'(?:belongsTo|hasMany|hasOne|belongsToMany|hasManyThrough|hasOneThrough|morphMany|morphOne)'
                .'\(\s*([A-Za-z0-9_]+)::class/s',
                $source,
                $found,
                PREG_SET_ORDER,
            );

            foreach ($found as [, $name, $related]) {
                $relations[$class][$name] = $related;
            }
        }

        return [$modelTable, $relations];
    }

    /** @return array<int,string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The text between the parentheses of the call starting at $offset.
     *
     * A balanced-paren scan rather than a regex, and quote-aware, so a ")" inside a string literal
     * does not close the call early and truncate the closure being searched — which would drop
     * columns silently and make this whole file report clean for the wrong reason.
     */
    private function balancedArgs(string $source, int $offset): ?string
    {
        $open = strpos($source, '(', $offset);

        if ($open === false) {
            return null;
        }

        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];

            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    private function shortPath(string $file): string
    {
        $root = str_replace('\\', '/', base_path()).'/';

        return str_replace($root, '', str_replace('\\', '/', $file));
    }

    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private function pluralise(string $value): string
    {
        if (str_ends_with($value, 'y') && preg_match('/[aeiou]y$/', $value) !== 1) {
            return substr($value, 0, -1).'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $value) === 1) {
            return $value.'es';
        }

        return $value.'s';
    }
}
