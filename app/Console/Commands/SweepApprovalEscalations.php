<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Workflow\EscalationService;
use Illuminate\Console\Command;

/**
 * The heartbeat behind approval SLAs (§34).
 *
 * Runs hourly from the scheduler, which on cPanel is one cron line calling `schedule:run` — no
 * Supervisor, no queue worker, no daemon (§1). If the cron is never installed, the platform still
 * works: nothing escalates, and that is a visibly missing feature rather than a broken one.
 *
 * WHY A COMMAND RATHER THAN A QUEUED JOB PER STAGE. A job per overdue stage means thousands of rows
 * in the jobs table on a system that has been down for a day, all racing to send the same reminder.
 * One sweep, walking tenants in order, sending at most one escalation level per stage per run, is
 * both cheaper and idempotent — and idempotent matters more, because a cron that overlaps itself is
 * a normal Tuesday on shared hosting.
 *
 * Safe to run twice: EscalationService stamps `last_escalated_level` in the same transaction as the
 * send, so a second run inside the same hour finds nothing to do.
 */
class SweepApprovalEscalations extends Command
{
    protected $signature = 'approvals:sweep
                            {--organization= : Limit the sweep to one organization id}';

    protected $description = 'Escalate approval stages that have passed their due date';

    public function handle(EscalationService $escalations, OrganizationContext $context): int
    {
        $only = $this->option('organization');

        if ($only !== null) {
            // Resolved without the tenant scope on purpose: the console has no signed-in user, so
            // there is no ambient tenant for the scope to compare against. The id comes from an
            // operator at a shell prompt, not from a request (§75 is about untrusted input, and
            // this is not that).
            $organization = $context->withoutScope(
                fn () => Organization::query()->whereKey((int) $only)->first()
            );

            if ($organization === null) {
                $this->error('No organization with id '.$only.'.');

                return self::FAILURE;
            }

            $sent = $context->within($organization, fn () => $escalations->sweepCurrentTenant());
        } else {
            $sent = $escalations->sweep();
        }

        $this->info($sent === 0
            ? 'No approval stages needed escalating.'
            : 'Escalated '.$sent.' approval stage(s).');

        return self::SUCCESS;
    }
}
