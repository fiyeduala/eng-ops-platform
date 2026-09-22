<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Every query one piece of work ran, kept in the order it ran them.
 *
 * A NUMBER IS NOT A DIAGNOSIS. "This page ran 63 queries" is true and useless: the thing a budget
 * failure has to say is *which* query ran 25 times, because that sentence names the fix and a
 * count does not. So this object keeps the SQL, not a counter, and every failure message it
 * produces leads with the repeats.
 *
 * REPEATS ARE EXACT STRING MATCHES, AND THAT IS NOT AN APPROXIMATION. Laravel hands the listener
 * parameterised SQL — the ids travel in the bindings, never in the text — so the same statement
 * issued once per row arrives as 25 byte-identical strings. Grouping on the raw text is therefore
 * precise rather than heuristic, and the one thing it deliberately does not do is collapse two
 * queries that differ only in an `in (?, ?, ?)` arity, because a widening `in` list is an eager
 * load working correctly and should not be reported as a smell.
 *
 * @see CountsQueries for how a tally is taken.
 */
final class QueryTally
{
    /**
     * @param  list<array{sql:string,time:float}>  $queries
     */
    public function __construct(
        private readonly array $queries,
    ) {}

    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map(static fn (array $q): string => $q['sql'], $this->queries);
    }

    /**
     * How many times each distinct statement ran, most-repeated first.
     *
     * @return array<string,int>
     */
    public function repeats(): array
    {
        $counts = array_count_values($this->all());

        arsort($counts);

        return $counts;
    }

    /**
     * The worst offender's repeat count — the number a per-row query makes grow.
     */
    public function worstRepeat(): int
    {
        $repeats = $this->repeats();

        return $repeats === [] ? 0 : (int) reset($repeats);
    }

    /**
     * The whole tally, written for somebody who is about to go and fix it.
     *
     * Repeated statements are printed first and in full; the tail is summarised, because a budget
     * failure that prints sixty distinct one-off queries buries the three that matter.
     */
    public function report(): string
    {
        $repeats = $this->repeats();
        $lines = [];

        foreach ($repeats as $sql => $times) {
            if ($times < 2) {
                continue;
            }

            $lines[] = sprintf('  %2dx  %s', $times, $this->truncate($sql));
        }

        if ($lines === []) {
            $lines[] = '  (no statement ran more than once — the cost is breadth, not repetition)';
        }

        $singles = count(array_filter($repeats, static fn (int $n): bool => $n === 1));

        return sprintf(
            "%d queries in all; %d distinct statements; %d ran exactly once.\nRepeated statements:\n%s",
            $this->count(),
            count($repeats),
            $singles,
            implode("\n", $lines),
        );
    }

    /**
     * Fail unless the work stayed inside its budget.
     *
     * The budget is a ceiling with the reason attached, never a bare integer: a later reader has to
     * be able to tell a deliberate allowance from a number somebody pasted in to make a red test
     * green, and the only difference between those two is whether the allowance says why.
     */
    public function assertWithin(int $budget, string $because): void
    {
        Assert::assertLessThanOrEqual(
            $budget,
            $this->count(),
            sprintf(
                "Query budget exceeded: %d queries against a ceiling of %d.\n%s\n\n%s\n\n"
                ."Raise the budget only if the extra queries are a fixed cost — one more join, one\n"
                ."more lookup. If the repeat count above tracks the number of rows on the page, the\n"
                .'budget is not the thing that is wrong.',
                $this->count(),
                $budget,
                $because,
                $this->report(),
            ),
        );
    }

    /**
     * Fail if any single statement ran more than $times — the per-row shape, named directly.
     */
    public function assertNoStatementRepeatedMoreThan(int $times, string $because): void
    {
        $offenders = array_filter($this->repeats(), static fn (int $n): bool => $n > $times);

        Assert::assertSame(
            [],
            $this->describe($offenders),
            sprintf("A statement ran more times than a fixed cost allows (ceiling %d).\n%s\n\n%s",
                $times, $because, $this->report()),
        );
    }

    /**
     * @param  array<string,int>  $offenders
     * @return list<string>
     */
    private function describe(array $offenders): array
    {
        $out = [];

        foreach ($offenders as $sql => $times) {
            $out[] = $times.'x '.$this->truncate($sql);
        }

        return $out;
    }

    private function truncate(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return strlen($sql) > 160 ? substr($sql, 0, 157).'...' : $sql;
    }
}
