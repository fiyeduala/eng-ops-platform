<?php

declare(strict_types=1);

namespace App\Engines\Expiry;

use App\Engines\Configuration\StatusMachine;
use App\Events\RecordExpiring;
use App\Models\ExpiryWatch;
use App\Support\ExpiryCatalogue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The daily look-ahead over every date a company asked to be warned about.
 *
 * IT WRITES NOTHING, WHICH IS THE POINT — the property inherited from the two hard-coded sweeps
 * this replaces, and the one most worth defending. There is no `is_overdue` column, no expiry
 * status, no "last warned at" stamp and no state anywhere that depends on this having run. Not
 * running it for a week loses a week of warnings; every register, every filter and every date badge
 * stays exactly as correct as it was, because all of them compare a date to today. A misconfigured
 * cron must be able to cost a company its REMINDERS and must never be able to make a lapsed
 * insurance certificate look valid.
 *
 * THAT IS ALSO WHY MILESTONES ARE EXACT. A watch speaks when a record is exactly thirty, seven or
 * one day out, so a sweep that does not run on the seventh day misses that warning rather than
 * firing it late alongside the next. The alternative — remembering what has been sent — is the
 * "last warned at" column that would put correctness at the mercy of the cron. Overdue records are
 * the deliberate exception: once a date has passed, a watch told to keep speaking does so every
 * day, because there the record going stale IS the hazard.
 *
 * SAFE TO RUN TWICE, but not free to: a second run inside the same day re-sends the same day's
 * milestones. Daily is the cadence the schedule sets.
 *
 * TENANT BY TENANT. Each company's watches are its own, so the sweep runs inside that
 * organization's context with the global scope doing its ordinary job, rather than reaching across
 * tenants with the scope switched off — which is the exact shape of bug §75 exists to prevent, and
 * a nightly job is the easiest place in a codebase to introduce one.
 */
class ExpirySweeper
{
    public function __construct(
        private readonly StatusMachine $statuses,
    ) {}

    /**
     * Every active watch for the company currently in context.
     *
     * @return int the number of warnings raised
     */
    public function sweepCurrentTenant(?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today())->startOfDay();
        $raised = 0;

        foreach (ExpiryWatch::query()->active()->orderBy('id')->get() as $watch) {
            $raised += $this->sweepWatch($watch, $today);
        }

        return $raised;
    }

    /**
     * One watch, against its register.
     *
     * @return int the number of warnings raised
     */
    public function sweepWatch(ExpiryWatch $watch, ?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today())->startOfDay();

        // Re-checked here and not only on save. A watch is long-lived configuration and the module
        // it names can be renamed or retired underneath it; a watch pointing at a register that no
        // longer offers that date falls silent rather than throwing inside a nightly job (§84).
        if (! ExpiryCatalogue::offers($watch->entity_type, $watch->date_field)) {
            return 0;
        }

        $class = ExpiryCatalogue::modelFor($watch->entity_type);

        if ($class === null) {
            return 0;
        }

        /** @var Model $prototype */
        $prototype = new $class;
        $field = $watch->date_field;

        // `whereDate`, NOT `where`, and this is not a stylistic preference — it is the difference
        // between the furthest milestone firing and never firing at all.
        //
        // A `date`-cast attribute is written through the model's datetime format, so the column
        // holds "2026-09-26 00:00:00" even where the migration declared a plain date. Compared as a
        // string against "2026-09-26" that value is GREATER, so a bare `<=` on the horizon silently
        // drops every record falling due on exactly the boundary day — which is the outermost
        // milestone, the one a company set deliberately and relies on most. The bug is invisible in
        // any test whose horizon is wider than its date. `whereDate` compares dates as dates on both
        // MySQL and SQLite and has no such edge.
        $query = $class::query()
            ->whereNotNull($field)
            ->whereDate($field, '<=', $today->copy()->addDays($watch->horizonDays())->toDateString());

        // A watch that falls silent once the date passes has no business loading records that
        // passed it — there may be years of them, and every one would be fetched only to be
        // discarded by speaksAt() below.
        if (! $watch->warn_when_overdue) {
            $query->whereDate($field, '>=', $today->toDateString());
        }

        // The register's own narrowing, where it has one. See HasExpiryDates::scopeExpiryWatchable:
        // a superseded certificate still carries next year's expiry date and is no longer the
        // certificate anybody should be warned about.
        $query->expiryWatchable();

        $this->excludeFinished($query, $prototype, $watch->entity_type);

        $raised = 0;

        // Chunked rather than ->get(): a company with ten thousand training certificates should not
        // put ten thousand models in memory on a shared host at half past six in the morning.
        $query->orderBy($field)->chunkById(200, function ($records) use ($watch, $today, $field, &$raised) {
            foreach ($records as $record) {
                $date = $record->getAttribute($field);

                if ($date === null) {
                    continue;
                }

                // Whole days, from midnight to midnight, and computed here rather than in the
                // listener so the entire sweep speaks as of one clock. Negative once the date has
                // gone by, which is what the listener words differently.
                $daysRemaining = (int) $today->diffInDays(Carbon::parse($date)->startOfDay(), false);

                if (! $watch->speaksAt($daysRemaining)) {
                    continue;
                }

                RecordExpiring::dispatch($record, $watch, $daysRemaining);
                $raised++;
            }
        });

        return $raised;
    }

    /**
     * Leave out the records that are already done with.
     *
     * A closed permit, a cancelled maintenance job and a superseded certificate all still carry the
     * date that was watched, and warning about them is worse than useless — it is the noise that
     * teaches somebody to ignore the warning about the one that is still live. Which statuses count
     * as finished is the company's own configuration, read from the same StatusMachine the register
     * screens read, so a company that invented a "withdrawn" status gets it honoured here without
     * anybody adding it to a list (§63).
     *
     * Registers with no status column simply skip this — a vendor prequalification has no lifecycle
     * to be finished with — and so do registers whose company has configured no terminal statuses.
     *
     * @param  Builder<Model>  $query
     */
    private function excludeFinished($query, Model $prototype, string $entityType): void
    {
        if (! Schema::hasColumn($prototype->getTable(), 'status_id')) {
            return;
        }

        $terminal = $this->statuses->statusesFor($entityType)
            ->where('is_terminal', true)
            ->pluck('id')
            ->all();

        if ($terminal === []) {
            return;
        }

        // `orWhereNull` matters: a record whose status was never set is unfinished, not finished,
        // and a bare whereNotIn on a nullable column excludes nulls in SQL.
        $query->where(fn ($q) => $q->whereNotIn('status_id', $terminal)->orWhereNull('status_id'));
    }
}
