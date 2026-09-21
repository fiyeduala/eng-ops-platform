<?php

declare(strict_types=1);

namespace App\Engines\Kpi;

use App\Models\KpiDefinition;
use App\Models\KpiSnapshot;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The nightly pass that turns today's figures into points on a line.
 *
 * WHAT THIS IS FOR, AND WHAT IT IS NOT FOR. It is for trends: docs/11 §5 wants historical values to
 * stay correct after the underlying records change, and a line redrawn from live data every time
 * somebody closes an old incident is a line that rewrites its own history. It is NOT a cache of
 * what people are about to look at. A viewer whose reach falls short of a stored figure's scope
 * still gets a live count — the snapshot simply is not offered to them.
 *
 * IT WRITES ONLY ITS OWN TABLE. Like the expiry sweep it sits beside, this changes no register, no
 * status and no flag. Not running it for a week costs a week of trend points and leaves every list,
 * badge and figure in the platform exactly as correct as it was. That property is worth more than
 * the trend line, and it is why the sweep is allowed to run unattended at all.
 *
 * IDEMPOTENT BY KEY, NOT BY MEMORY. A second run on the same day recomputes and overwrites the same
 * point rather than appending a second one, because the unique key on `kpi_snapshots` is the
 * measure, the period label and the scope. There is no "last run" stamp to get out of step with
 * reality, and re-running after a fix is always safe.
 *
 * EVERY FIGURE IT WRITES IS COUNTED THROUGH NOBODY'S VISIBILITY. That is the whole reason the read
 * path is guarded: see `KpiEvaluator::countWholeScope()` and `ReachInspector`.
 */
class SnapshotSweeper
{
    /**
     * Department-scoped measures are stored for these altitudes only.
     *
     * A company can carry teams and sections five levels deep, and a KPI stored at every node would
     * multiply one measure into hundreds of rows a night for scopes nobody has a dashboard for.
     * Divisions and departments are the altitudes §53 names as having boards, so they are the
     * altitudes that get a stored history. Anything finer is still countable live at any time; it
     * simply has no trend line.
     *
     * @var array<int,OrgUnitType>
     */
    private const UNIT_ALTITUDES = [OrgUnitType::Division, OrgUnitType::Department];

    public function __construct(
        private readonly KpiEvaluator $evaluator,
    ) {}

    /**
     * Snapshot every storable measure this company watches. Returns how many points were written.
     *
     * The caller is responsible for the organization context, exactly as the expiry sweep requires,
     * so the ordinary tenant scope does the isolating rather than being switched off for a bulk
     * read (§75).
     */
    public function sweepCurrentTenant(?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf === null ? Carbon::now() : Carbon::parse($asOf);
        $written = 0;

        // `employee` is absent from KpiSnapshot::SCOPE_TYPES on purpose — a figure about one named
        // person is never stored. See the migration.
        foreach (array_keys(KpiSnapshot::SCOPE_TYPES) as $scopeType) {
            $definitions = KpiDefinition::query()
                ->active()
                ->atScope($scopeType)
                ->orderBy('id')
                ->get();

            if ($definitions->isEmpty()) {
                continue;
            }

            foreach ($this->scopeIdsFor($scopeType) as $scopeId) {
                foreach ($definitions as $kpi) {
                    $written += $this->record($kpi, $scopeType, $scopeId, $asOf);
                }
            }
        }

        return $written;
    }

    /**
     * The instances one altitude has today.
     *
     * @return array<int,int|null>
     */
    private function scopeIdsFor(string $scopeType): array
    {
        if ($scopeType === 'organization') {
            // One instance, identified by the absence of an id rather than by a sentinel, because
            // the unique key has to be able to tell "the company" from "department 0".
            return [null];
        }

        if ($scopeType === 'department') {
            return OrgUnit::query()
                ->active()
                ->whereIn('type', array_map(fn (OrgUnitType $t) => $t->value, self::UNIT_ALTITUDES))
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // Closed projects stop accruing points rather than repeating their final figure nightly:
        // a flat tail on a trend line reads as "nothing is happening here", which for a delivered
        // project is true but for a stalled one is exactly the wrong thing to say.
        return Project::query()
            ->active()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Write one point, or overwrite the one already standing in its place.
     *
     * An unavailable figure is RECORDED AS UNAVAILABLE rather than skipped. Skipping would leave
     * yesterday's number sitting in the series under today's label the moment a measure broke —
     * a flat line that reads as stability while the thing measuring it is dead.
     */
    private function record(KpiDefinition $kpi, string $scopeType, ?int $scopeId, CarbonInterface $asOf): int
    {
        $result = $this->evaluator->countWholeScope($kpi, $scopeType, $scopeId, $asOf);

        KpiSnapshot::query()->updateOrCreate(
            [
                'kpi_definition_id' => $kpi->getKey(),
                'period_key' => $kpi->periodKeyFor($asOf),
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ],
            [
                // Copied, not referenced. A definition repointed at another register must not
                // relabel the points already on the line. See the migration.
                'entity_type' => $kpi->entity_type,
                'value' => $result->available ? $result->value : null,
                'unavailable_reason' => $result->available ? null : $result->reason,
                'computed_at' => $asOf,
            ],
        );

        return 1;
    }
}
