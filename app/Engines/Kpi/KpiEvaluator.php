<?php

declare(strict_types=1);

namespace App\Engines\Kpi;

use App\Engines\Access\ActorProfile;
use App\Engines\Query\FilterCompiler;
use App\Models\KpiDefinition;
use App\Models\KpiSnapshot;
use App\Models\User;
use App\Support\KpiCatalogue;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a definition into a number, for one person, at one moment.
 *
 * EVERY TOTAL IS COUNTED THROUGH THE VIEWER'S OWN VISIBILITY (§61). The first thing every query
 * here does is `visibleTo($viewer, <register>.view)`, before a single filter is applied, and there
 * is no path through this class that skips it — no "system" mode, no `withoutGlobalScopes`, no
 * flag an eager caller could pass. That is not a convenience: a count is an oracle. "How many
 * confidential incidents are open" is a question somebody without clearance must not be able to ask
 * even in the reduced form of a single digit, and a dashboard is exactly the screen where such a
 * digit looks harmless. The figure two people see therefore legitimately differs, and that is the
 * design rather than a bug to reconcile.
 *
 * NOTHING IS STORED. The number is computed when it is asked for and thrown away, which is the only
 * arrangement in which the paragraph above can hold: a cached total belongs to whoever it was
 * counted for, and a cache keyed by KPI alone would hand one person's reach to the next reader.
 *
 * NOTHING FROM A DEFINITION REACHES A QUERY UNCHECKED. Field names are looked up in KpiCatalogue at
 * evaluation time and not merely at save time, because a definition outlives the register it names
 * (§84). An unrecognised field is dropped and the KPI reports itself unavailable rather than
 * quietly answering a narrower question than it claims to.
 *
 * A KPI THAT CANNOT BE COUNTED SAYS SO. There is no zero returned for "the register is gone" or
 * "you can see none of it" — those come back as an unavailable result with a reason, because a
 * fabricated zero on a safety dashboard is the most dangerous number this class could produce.
 */
class KpiEvaluator
{
    public function __construct(
        private readonly ActorProfile $actors,

        // The same compiler the report engine uses, which is the point of it being a separate
        // class: docs/11 §1 requires a report and the KPI beside it to narrow a register in
        // exactly the same way, and the only durable way to guarantee that is for there to be
        // one implementation rather than two that agree today.
        private readonly FilterCompiler $filters,
    ) {}

    /**
     * Count one KPI.
     *
     * @param  int|null  $scopeId  which department, project or person this instance is about;
     *                             ignored for an organization-scoped KPI
     */
    public function evaluate(
        KpiDefinition $kpi,
        ?User $viewer,
        ?CarbonInterface $asOf = null,
        ?int $scopeId = null,
    ): KpiResult {
        $viewerId = $viewer?->getKey();

        if ($viewer === null) {
            return KpiResult::unavailable('Nobody is signed in, so there is nothing to count.');
        }

        // A measure belongs to the company that wrote it (§75). The tenant scope already keeps one
        // company's definitions off another's screens, so reaching here with somebody else's KPI
        // means it arrived by key rather than by listing — and the danger is not that the rival
        // reads Meridian's records, which the visibility below would stop anyway, but that they
        // count their OWN records through a definition Meridian is still editing. "Open incidents"
        // is one filter away from meaning something else entirely, and the figure would carry the
        // rival's letterhead while answering Meridian's question.
        if ($kpi->organization_id !== $viewer->organization_id) {
            return KpiResult::unavailable('This measure belongs to another company.', $viewerId);
        }

        if ($unusable = $this->unusable($kpi, $viewerId)) {
            return $unusable;
        }

        /** @var class-string<Model> $class */
        $class = KpiCatalogue::modelFor($kpi->entity_type);

        $asOf = $asOf === null ? Carbon::now() : Carbon::parse($asOf);
        [$windowStart, $windowEnd] = $this->window($kpi, $asOf);

        $base = $this->baseQuery($kpi, $class, $viewer, $windowStart, $windowEnd, $scopeId);

        if ($base === null) {
            return KpiResult::unavailable(
                'This measure filters on something that register no longer offers.', $viewerId,
            );
        }

        return $this->aggregate($kpi, $base, $viewerId, $windowStart, $windowEnd);
    }

    /**
     * Count every active KPI at one altitude, for one person.
     *
     * @return array<int,array{kpi:KpiDefinition,result:KpiResult}>
     */
    public function evaluateScope(
        string $scope,
        ?User $viewer,
        ?int $scopeId = null,
        ?CarbonInterface $asOf = null,
    ): array {
        $rows = [];

        // `visibleTo` on the DEFINITIONS too, not only on the records. Which measures a company
        // watches is itself a statement about the company, and somebody without `kpi.view` should
        // not learn that a "days since last lost-time injury" KPI exists by receiving an empty one.
        $definitions = KpiDefinition::query()
            ->visibleTo($viewer, 'kpi.view')
            ->active()
            ->atScope($scope)
            ->ordered()
            ->get();

        foreach ($definitions as $kpi) {
            $rows[] = ['kpi' => $kpi, 'result' => $this->evaluate($kpi, $viewer, $asOf, $scopeId)];
        }

        return $rows;
    }

    /**
     * Count this measure over a WHOLE SCOPE, through nobody's visibility.
     *
     * THE ONLY PLACE IN THE PLATFORM THAT COUNTS WITHOUT A VIEWER, and it exists for exactly one
     * caller: the nightly sweep that writes `kpi_snapshots`. A trend line has to be the company's
     * line — if each point were counted through whoever happened to trigger the sweep, the series
     * would step up and down with staff changes rather than with the business, and it could never
     * be shown to a second person at all.
     *
     * WHAT MAKES THIS SAFE IS NOT HERE. It is at the read end: `ReachInspector` hands a stored
     * figure to somebody only when their reach contains the whole scope it was counted over. This
     * method's output must therefore never be returned to a request directly. `countedFor` comes
     * back null, which is the marker for "nobody's figure" — a screen that prints a KpiResult with
     * a null `countedFor` without a reach verdict is the bug this whole design is defending against.
     *
     * Tenancy is NOT waived. `BelongsToOrganization` puts its global scope on the query as usual,
     * so this counts one company's rows within whatever organization context the caller has set.
     *
     * @param  string  $scopeType  organization | department | project
     */
    public function countWholeScope(
        KpiDefinition $kpi,
        string $scopeType,
        ?int $scopeId = null,
        ?CarbonInterface $asOf = null,
    ): KpiResult {
        if ($unusable = $this->unusable($kpi, null)) {
            return $unusable;
        }

        if (! array_key_exists($scopeType, KpiSnapshot::SCOPE_TYPES)) {
            return KpiResult::unavailable('That is not an altitude a figure can be stored at.');
        }

        if ($kpi->scope !== $scopeType) {
            return KpiResult::unavailable('This measure is not about that kind of scope.');
        }

        if ($scopeType !== 'organization' && $scopeId === null) {
            return KpiResult::unavailable('This measure needs to be told which one it is about.');
        }

        /** @var class-string<Model> $class */
        $class = KpiCatalogue::modelFor($kpi->entity_type);

        $asOf = $asOf === null ? Carbon::now() : Carbon::parse($asOf);
        [$windowStart, $windowEnd] = $this->window($kpi, $asOf);

        // No `visibleTo`. Compare with `baseQuery()` one method below: the difference between the
        // company's figure and a person's figure is this single missing call, and it is left
        // visible rather than hidden behind a flag for that reason.
        $base = $this->narrow($class::query(), $kpi, $windowStart, $windowEnd, $scopeId);

        if ($base === null) {
            return KpiResult::unavailable('This measure filters on something that register no longer offers.');
        }

        return $this->aggregate($kpi, $base, null, $windowStart, $windowEnd);
    }

    // ================================================================== the query

    /**
     * The checks that make a definition answerable at all, independent of who is asking.
     *
     * Returns the result to hand back, or null when the definition is sound.
     */
    private function unusable(KpiDefinition $kpi, ?int $viewerId): ?KpiResult
    {
        if (! $kpi->is_active) {
            return KpiResult::unavailable('This measure has been switched off.', $viewerId);
        }

        if (KpiCatalogue::modelFor($kpi->entity_type) === null) {
            return KpiResult::unavailable(
                'This measure counts a register the platform no longer offers.', $viewerId,
            );
        }

        if (! array_key_exists($kpi->aggregate, KpiDefinition::AGGREGATES)) {
            return KpiResult::unavailable('This measure asks for a calculation the platform does not do.', $viewerId);
        }

        if ($kpi->needsMeasure() && ! KpiCatalogue::offersMeasure($kpi->entity_type, (string) $kpi->measure_field)) {
            return KpiResult::unavailable(
                'This measure adds up a figure that register no longer offers.', $viewerId,
            );
        }

        return null;
    }

    /**
     * The population every aggregate starts from, or null where the definition cannot be honoured.
     *
     * @param  class-string<Model>  $class
     * @return Builder<Model>|null
     */
    private function baseQuery(
        KpiDefinition $kpi,
        string $class,
        User $viewer,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
        ?int $scopeId,
    ): ?Builder {
        // FIRST, BEFORE ANYTHING ELSE. Every narrowing below only ever removes rows, so starting
        // here is what makes the rest of this method unable to widen the answer past the viewer.
        $query = $class::query()->visibleTo($viewer, $kpi->viewPermissionFor($class));

        return $this->narrow($query, $kpi, $windowStart, $windowEnd, $scopeId);
    }

    /**
     * Everything the definition says, applied to a population somebody else chose.
     *
     * Every step in here only ever REMOVES rows, which is the property that lets one method serve
     * both a viewer-filtered count and a whole-scope one without the second being able to widen
     * past the first's starting point.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>|null
     */
    private function narrow(
        Builder $query,
        KpiDefinition $kpi,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
        ?int $scopeId,
    ): ?Builder {
        if (! $this->filters->apply($query, $kpi->entity_type, $kpi->baseFilters())) {
            return null;
        }

        if ($windowStart !== null && $windowEnd !== null) {
            $field = (string) $kpi->date_field;

            if (! KpiCatalogue::offersDate($kpi->entity_type, $field)) {
                return null;
            }

            // `whereBetween` on the raw column rather than `whereDate`, because the window has
            // already been widened to whole days at both ends — and a `date`-cast column compared
            // as a string drops the boundary day, which is the bug the expiry sweep was carrying.
            $query->whereNotNull($field)->whereBetween($field, [$windowStart, $windowEnd]);
        }

        return $this->applyScopeBinding($query, $kpi, $scopeId);
    }

    /**
     * Bind the KPI to the one department, project or person it is about.
     *
     * A NARROWING AND NEVER A WIDENING. The id arrives from a page — a project dashboard, a
     * department page — so it is a URL id and is treated as one (§84): it is applied as an extra
     * `where` ON TOP of the visibility scope, never instead of it. Somebody who passes another
     * department's id gets the intersection, which for them is nothing at all.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyScopeBinding(Builder $query, KpiDefinition $kpi, ?int $scopeId): Builder
    {
        if ($kpi->scope === 'organization' || $scopeId === null) {
            return $query;
        }

        $column = match ($kpi->scope) {
            'department' => KpiCatalogue::orgUnitColumnFor($kpi->entity_type),
            'project' => KpiCatalogue::projectColumnFor($kpi->entity_type),
            'employee' => KpiCatalogue::employeeColumnFor($kpi->entity_type),
            default => null,
        };

        if ($column === null) {
            // The register cannot be cut that way. Counting the whole company instead would be a
            // silently wrong answer on a project page, so nothing matches rather than everything.
            return $query->whereRaw('1 = 0');
        }

        // A department rolls up. "Incidents in Engineering" means the division, not the one desk
        // at the top of it, and an organogram is a tree precisely so that it can be read that way.
        if ($kpi->scope === 'department') {
            return $query->whereIn($column, $this->actors->subtreeOf($scopeId));
        }

        return $query->where($column, $scopeId);
    }

    // ================================================================== the arithmetic

    /**
     * @param  Builder<Model>  $base
     */
    private function aggregate(
        KpiDefinition $kpi,
        Builder $base,
        ?int $viewerId,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
    ): KpiResult {
        $measure = $kpi->needsMeasure()
            ? $base->getModel()->qualifyColumn((string) $kpi->measure_field)
            : null;

        $result = fn (?float $value, int $population) => new KpiResult(
            value: $value,
            population: $population,
            countedFor: $viewerId,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
        );

        if ($kpi->needsNumerator()) {
            // Counted before the numerator narrows it, and counted FIRST, because a denominator of
            // zero is the answer rather than a division to attempt.
            $denominator = (clone $base)->count();

            if ($denominator === 0) {
                return $result(null, 0);
            }

            $numerator = clone $base;

            if (! $this->filters->apply($numerator, $kpi->entity_type, $kpi->numeratorFilters())) {
                return KpiResult::unavailable(
                    'This measure filters on something that register no longer offers.', $viewerId,
                );
            }

            $ratio = $numerator->count() / $denominator;

            return $result($kpi->aggregate === 'percent' ? $ratio * 100 : $ratio, $denominator);
        }

        if ($kpi->aggregate === 'count') {
            $count = $base->count();

            return $result((float) $count, $count);
        }

        // `population` is counted separately from the aggregate on purpose: an average over three
        // rows and an average over three thousand are different claims, and a tile that cannot say
        // which is a tile that invites somebody to act on noise.
        $population = (clone $base)->count();

        if ($population === 0) {
            return $result(null, 0);
        }

        $value = $kpi->aggregate === 'sum'
            ? $base->sum($measure)
            : $base->avg($measure);

        return $result($value === null ? null : (float) $value, $population);
    }

    // ================================================================== the window

    /**
     * The period, as two instants.
     *
     * Computed from the moment the KPI is ASKED about rather than stored, which is what lets one
     * definition answer "this month" today and "last March" when a report is re-run against last
     * March. Both ends are inclusive whole days, so a record created at ten to midnight on the last
     * day of the month is in that month.
     *
     * @return array{0:CarbonInterface|null,1:CarbonInterface|null}
     */
    private function window(KpiDefinition $kpi, CarbonInterface $asOf): array
    {
        if (! $kpi->isWindowed()) {
            return [null, null];
        }

        $at = Carbon::parse($asOf);

        return match ($kpi->period) {
            'day' => [$at->copy()->startOfDay(), $at->copy()->endOfDay()],
            'week' => [$at->copy()->startOfWeek(), $at->copy()->endOfWeek()],
            'month' => [$at->copy()->startOfMonth(), $at->copy()->endOfMonth()],
            'quarter' => [$at->copy()->startOfQuarter(), $at->copy()->endOfQuarter()],
            'year' => [$at->copy()->startOfYear(), $at->copy()->endOfYear()],
            default => [null, null],
        };
    }
}
