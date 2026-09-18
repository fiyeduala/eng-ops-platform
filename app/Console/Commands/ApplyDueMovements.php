<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Models\Organization;
use App\Services\EmployeeMovementService;
use Illuminate\Console\Command;

/**
 * Writes approved promotions and transfers onto the personnel record on the day they take effect.
 *
 * THIS ONE DOES WRITE, WHICH MAKES IT THE OPPOSITE OF SweepAssetExpiries, and the difference is worth
 * stating plainly. That sweep is a reminder service and losing a week of it costs a week of warnings.
 * This one is the hand that moves somebody from Engineer to Senior Engineer, and a cron that has not
 * run for a week means a week of people sitting in the wrong position, on the wrong reporting line,
 * with their leave approvals going to a manager who is no longer theirs.
 *
 * SO IT IS SAFE TO RUN LATE AND SAFE TO RUN TWICE. `dueToApply()` asks for approved movements with
 * `applied_at` still null and an effective date of today OR EARLIER, so a missed night is caught up
 * on the next run rather than skipped; and `apply()` stamps `applied_at` inside the same transaction
 * that writes the employee, so a second pass the same day finds nothing to do. Running it by hand
 * after fixing a broken cron is the intended recovery and needs no flags.
 *
 * THE SCREEN AND THE SWEEP CALL THE SAME METHOD. There is no console-only shortcut into the employee
 * table: `employee-movements.apply` and this command both go through EmployeeMovementService::apply(),
 * which locks the employee row, writes only the columns the movement actually changes, and records
 * the act on both records. A second code path would be a second set of rules to keep in step (§84).
 *
 * NOBODY IS THE ACTOR. `apply(..., null)` is passed deliberately: the platform did this because the
 * effective date arrived, and attributing it to whoever happens to own the cron account would put a
 * name on the timeline that did not decide anything. The decision is already on the record — it is
 * the approval, with the approver's name on it.
 *
 * TENANT BY TENANT, IN ORDER, for the same reason as every other sweep: each organization is entered
 * through OrganizationContext so the global scope keeps doing its ordinary job rather than being
 * switched off for a bulk read (§75).
 */
class ApplyDueMovements extends Command
{
    protected $signature = 'movements:apply
                            {--organization= : Limit the run to one organization id}
                            {--dry-run : List what would be applied and change nothing}';

    protected $description = 'Apply approved employee movements that have reached their effective date';

    public function handle(EmployeeMovementService $movements, OrganizationContext $context): int
    {
        $only = $this->option('organization');
        $dryRun = (bool) $this->option('dry-run');

        // Resolved outside the tenant scope: the console has no signed-in user, so there is no
        // ambient tenant to compare against, and the id came from an operator at a shell prompt.
        $organizations = $context->withoutScope(fn () => Organization::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get());

        if ($organizations->isEmpty()) {
            $this->error($only === null ? 'No organizations.' : 'No organization with id '.$only.'.');

            return self::FAILURE;
        }

        $applied = 0;
        $failed = 0;

        foreach ($organizations as $organization) {
            $context->within($organization, function () use ($movements, $dryRun, &$applied, &$failed) {
                foreach ($movements->dueToApply() as $movement) {
                    if ($dryRun) {
                        $this->line('  would apply '.$movement->number.' — '.$movement->label());
                        $applied++;

                        continue;
                    }

                    // One bad movement must not stop the rest. A refusal here is a real condition —
                    // the employee was exited between approval and the effective date, say — and it
                    // belongs in the output where somebody reads it, not thrown out of the command.
                    try {
                        $movements->apply($movement, null);
                        $applied++;
                    } catch (\DomainException $e) {
                        $failed++;
                        $this->warn('  '.$movement->number.': '.$e->getMessage());
                    }
                }
            });
        }

        $this->info($applied === 0 && $failed === 0
            ? 'No movements have reached their effective date.'
            : ($dryRun ? $applied.' movement(s) would be applied.' : 'Applied '.$applied.' movement(s).'));

        if ($failed > 0) {
            $this->warn($failed.' movement(s) could not be applied and are listed above.');
        }

        return self::SUCCESS;
    }
}
