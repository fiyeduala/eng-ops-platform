<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Engines\Kpi\SnapshotSweeper;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Take today's reading of every measure a company watches.
 *
 * Sits beside `expiry:sweep` and shares its two safety properties: it writes only its own table, so
 * a cron that never runs costs trend points and corrupts nothing; and it works tenant by tenant
 * inside each organization's own context, so the global scope keeps doing its ordinary job rather
 * than being switched off for a bulk read (§75).
 *
 * `--as-of` exists for backfilling a line after a measure is created, and for tests. It changes
 * which period label the points are filed under, so running it for a past date rewrites that date's
 * points rather than today's — which is the intended behaviour and worth knowing before using it on
 * a live company.
 */
class SweepKpiSnapshots extends Command
{
    protected $signature = 'kpi:snapshot
                            {--organization= : Limit the sweep to one organization id}
                            {--as-of= : Take the reading as at this date instead of today}';

    protected $description = 'Record today’s figure for every measure each company watches';

    public function handle(SnapshotSweeper $sweeper, OrganizationContext $context): int
    {
        $only = $this->option('organization');
        $asOf = $this->option('as-of');

        // Resolved without the tenant scope for the same reason the expiry sweep does it: a console
        // has no signed-in user, so there is no ambient tenant, and the id came from an operator at
        // a shell prompt rather than from a request.
        $organizations = $context->withoutScope(fn () => Organization::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get());

        if ($organizations->isEmpty()) {
            $this->error($only === null ? 'No organizations.' : 'No organization with id '.$only.'.');

            return self::FAILURE;
        }

        try {
            $moment = $asOf === null ? null : Carbon::parse($asOf);
        } catch (\Throwable) {
            $this->error('Could not read “'.$asOf.'” as a date.');

            return self::FAILURE;
        }

        $written = 0;

        foreach ($organizations as $organization) {
            $written += $context->within($organization, fn () => $sweeper->sweepCurrentTenant($moment));
        }

        $this->info($written === 0
            ? 'No measures are set up to be recorded.'
            : 'Recorded '.$written.' figure(s).');

        return self::SUCCESS;
    }
}
