<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Counting what a piece of work actually asked the database (§89).
 *
 * WHY THIS EXISTS WHEN `preventLazyLoading()` IS ALREADY ON. AppServiceProvider turns strict mode
 * on outside production, so the textbook N+1 — a blade touching `$row->project->name` inside a
 * loop — is already an exception rather than a slow page, and no budget is needed to catch it.
 * What strict mode cannot see is the N+1 that was *written deliberately*: a permission asked once
 * per row, a status vocabulary re-read per record, a numbering format fetched inside a foreach, a
 * policy that opens its own query. Every one of those is an explicit, legal query. Every one of
 * them multiplies by the row count. They are invisible on a seeded page of three records and they
 * are the whole cost of a page of two hundred, and this trait exists because nothing else in the
 * suite can tell those two pages apart.
 *
 * WHICH IS WHY THE GROWTH TESTS ARE THE POINT AND THE CEILINGS ARE THE SCAFFOLD. A budget number
 * is a judgement about today's schema and it will drift; `assertCostDoesNotGrowWith()` asserts the
 * shape instead — the same page over N rows and over 3N rows must cost the same — and that claim
 * stays true through every refactor that does not reintroduce the bug. A ceiling catches a
 * regression early and loudly; the growth assertion is the one that is still correct in a year.
 *
 * DB::listen IS USED RATHER THAN THE QUERY LOG because the log is disabled by default, accumulates
 * across a whole test method, and holds bindings that would make two identical statements look
 * distinct. Exactly one listener is ever attached, and it writes into a sink that only exists while
 * a measurement is running — so the fixture built above a measurement never lands in the numbers
 * below it, and a test method that measures four times does not count the first run four times.
 */
trait CountsQueries
{
    /**
     * Where a running measurement collects its statements; null between measurements.
     *
     * The listener is never removed. `forget(QueryExecuted::class)` would drop every listener on
     * that event, including ones the framework or a package registered, and there is no API to put
     * those back — so the sink is switched off instead of the listener being torn down.
     *
     * @var list<array{sql:string,time:float}>|null
     */
    private ?array $querySink = null;

    private bool $queryListenerAttached = false;

    /**
     * Run $work with a listener attached and hand back everything it asked the database.
     *
     * @template T
     *
     * @param  callable():T  $work
     */
    protected function measureQueries(callable $work, mixed &$result = null): QueryTally
    {
        if (! $this->queryListenerAttached) {
            DB::listen(function (QueryExecuted $event): void {
                if ($this->querySink !== null) {
                    $this->querySink[] = ['sql' => $event->sql, 'time' => $event->time];
                }
            });

            $this->queryListenerAttached = true;
        }

        $this->querySink = [];

        try {
            $result = $work();
            $collected = $this->querySink ?? [];
        } finally {
            $this->querySink = null;
        }

        return new QueryTally($collected);
    }

    /**
     * Run read-only work once and throw the numbers away, so what follows is the steady state.
     *
     * THE FIRST REQUEST IN A TEST PROCESS IS NOT THE PAGE. It pays for things that are paid once
     * and then never again — the settings row a config reader memoises, the permission catalogue
     * resolved on first ask, a session record written rather than read. Measured for real: an
     * executive board with no tiles on it cost 13 queries as the first request of a method and 7
     * as the second. Those six are a genuine one-off in production too; counting them would make
     * every "before" larger than every "after" and turn a growth assertion into a coin toss.
     *
     * Only read-only work may be warmed. Handing this a request that writes would run the write
     * twice, and a budget measured against a fixture the warm-up already changed is measuring
     * something nobody will ever load.
     *
     * @param  callable():mixed  $work
     */
    protected function warmUp(callable $work): void
    {
        $work();
    }

    /**
     * The same work over a small fixture and a larger one must cost the same number of queries.
     *
     * $grow is handed the number of extra records to create and must produce them; $work is run
     * before and after. A page whose cost is a function of its row count fails here with both
     * numbers and the statement that grew, which is the sentence that identifies the loop.
     *
     * @param  callable(int):void  $grow
     * @param  callable():mixed  $work
     */
    protected function assertCostDoesNotGrowWith(callable $grow, callable $work, int $extra, string $because): void
    {
        $this->warmUp($work);

        $before = $this->measureQueries($work);

        $grow($extra);

        // Warmed AGAIN, and this is the subtle one. Creating records invalidates the caches a
        // request would otherwise find warm — a settings row, a status vocabulary, a numbering
        // format — so the first load after a write repays a one-off cost that has nothing to do
        // with the rows added. Measured for real: a briefing cost 3 queries warm and 6 on the
        // first run after twelve documents were created, which is not an N+1 and would read as
        // one. Production loads a page against a warm cache; so does this.
        $this->warmUp($work);

        $after = $this->measureQueries($work);

        // Fewer queries after growing is as much a surprise as more, and usually means the second
        // run measured a different code path (an empty-state branch the first run skipped, a cache
        // that only warms once). Equality rather than <= says so out loud.
        Assert::assertSame(
            $before->count(),
            $after->count(),
            sprintf(
                "The cost of this work tracks the number of rows.\n%s\n\n"
                ."%d queries over the original fixture, %d after adding %d more records.\n\n"
                ."BEFORE\n%s\n\nAFTER\n%s\n\n"
                .'A difference of roughly the number added is a query inside a loop; a difference of '
                ."one or two\nis more likely a branch the smaller fixture never reached.",
                $because,
                $before->count(),
                $after->count(),
                $extra,
                $before->report(),
                $after->report(),
            ),
        );
    }
}
