<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Models\Organization;
use App\Services\VisitorService;
use Illuminate\Console\Command;

/**
 * Signs out everybody the front desk forgot.
 *
 * THE COST OF NOT DOING THIS is not a missing feature, it is a register nobody believes. Badges do
 * not get handed back; people leave through the car park. Within a fortnight the roll call shows
 * forty names, thirty of whom went home last Tuesday, and the first time it actually matters — an
 * alarm, an evacuation, somebody counting heads at an assembly point — the sheet is useless and
 * whoever is holding it knows it.
 *
 * SO THE PLATFORM GUESSES, AND SAYS SO. Everything this command closes is marked `auto_closed`, its
 * activity entry reads "Nobody witnessed this departure", and the roll call screen shows a witnessed
 * sign-out differently from an assumed one. A guess that is labelled as a guess is worth having; a
 * guess that looks like a fact is the thing this whole register exists to avoid.
 *
 * SAFE TO RUN LATE AND SAFE TO RUN TWICE, like every other sweep. It asks for visits checked in
 * before the organization's configured cut-off and not yet checked out, so a missed night is caught
 * up on the next run rather than skipped, and a second pass the same evening finds nothing to do.
 *
 * EACH COMPANY HAS ITS OWN CUT-OFF, which is why the work is per organization rather than one query
 * across the table. A head office closing at six and a site running a night shift cannot share an
 * end of day, and `visitor.auto_close_at` is a setting for that reason (§47). An organization that
 * turns the sweep off keeps its stragglers open, which is the right answer for a gatehouse that is
 * manned around the clock and signs everybody out properly.
 *
 * NOBODY IS THE ACTOR, for the reason movements:apply passes null: the platform did this because the
 * clock reached the cut-off, and putting the cron account's name on it would claim somebody watched
 * a visitor leave when nobody did.
 */
class CloseOutVisitors extends Command
{
    protected $signature = 'visitors:close-out
                            {--organization= : Limit the run to one organization id}';

    protected $description = 'Sign out visitors still shown on site at the end of the day';

    public function handle(VisitorService $visitors, OrganizationContext $context): int
    {
        $only = $this->option('organization');

        // Resolved outside the tenant scope: the console has no signed-in user, so there is no
        // ambient tenant, and the id came from an operator at a shell prompt (§75).
        $organizations = $context->withoutScope(fn () => Organization::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get());

        if ($organizations->isEmpty()) {
            $this->error($only === null ? 'No organizations.' : 'No organization with id '.$only.'.');

            return self::FAILURE;
        }

        $closed = 0;

        foreach ($organizations as $organization) {
            $context->within($organization, function () use ($visitors, $organization, &$closed) {
                $count = $visitors->closeOutStragglers();
                $closed += $count;

                if ($count > 0) {
                    $this->line('  '.$organization->name.': signed out '.$count.' visitor(s).');
                }
            });
        }

        $this->info($closed === 0
            ? 'Nobody was left on site.'
            : 'Signed out '.$closed.' visitor(s) who were still shown on site.');

        return self::SUCCESS;
    }
}
