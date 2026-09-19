<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Engines\Expiry\ExpirySweeper;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * The daily look-ahead over every date a company asked to be warned about.
 *
 * THIS REPLACES `assets:sweep`, WHICH WATCHED TWO DATES OUT OF ELEVEN. Maintenance due dates and
 * vehicle paperwork were swept because somebody wrote a sweep for them; permits, training
 * certificates, vendor prequalifications, document review dates and the rest were not, and the
 * reason was never a decision — it was simply that nobody had written the second sweep yet. A
 * company that wanted warning about its work-at-height permits had to ask for a code change.
 *
 * Now the list is the company's, not the platform's, and adding a watch is a screen rather than a
 * deployment (§73). What used to be two hard-coded sweeps are two seeded rows that an administrator
 * can retune or switch off — which also means they can now be switched off, and that is why the
 * watch is auditable.
 *
 * IT WRITES NOTHING, WHICH IS THE POINT, and this is the property carried over intact from the
 * command it replaces. No `is_overdue` column, no expiry status, no "last warned" stamp. Not running
 * this for a week loses a week of warnings; every register, filter and date badge stays exactly as
 * correct as it was. A misconfigured cron must be able to cost a company its REMINDERS and must
 * never be able to make a lapsed insurance certificate look valid.
 *
 * TENANT BY TENANT, IN ORDER, each inside its own context so the global scope keeps doing its
 * ordinary job rather than being switched off for a bulk read (§75).
 */
class SweepExpiries extends Command
{
    protected $signature = 'expiry:sweep
                            {--organization= : Limit the sweep to one organization id}';

    protected $description = 'Warn about the dates each company asked to be warned about';

    public function handle(ExpirySweeper $sweeper, OrganizationContext $context): int
    {
        $only = $this->option('organization');

        // Resolved without the tenant scope for the same reason the escalation sweep does it: the
        // console has no signed-in user, so there is no ambient tenant to compare against, and the
        // id came from an operator at a shell prompt rather than from a request.
        $organizations = $context->withoutScope(fn () => Organization::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get());

        if ($organizations->isEmpty()) {
            $this->error($only === null ? 'No organizations.' : 'No organization with id '.$only.'.');

            return self::FAILURE;
        }

        $raised = 0;

        foreach ($organizations as $organization) {
            $raised += $context->within($organization, fn () => $sweeper->sweepCurrentTenant());
        }

        $this->info($raised === 0
            ? 'Nothing reached a warning milestone today.'
            : 'Raised '.$raised.' expiry warning(s).');

        return self::SUCCESS;
    }
}
