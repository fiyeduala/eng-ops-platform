<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Models\Organization;
use App\Services\AssetMaintenanceService;
use App\Services\VehicleDocumentService;
use Illuminate\Console\Command;

/**
 * The daily look-ahead over maintenance due dates and vehicle paperwork.
 *
 * ONE COMMAND FOR BOTH SWEEPS, and the reason is neither laziness nor cost. Both answer the same
 * question in the same shape — "what falls due inside this company's warning window" — both read a
 * per-organization setting to decide the window, and both raise an event rather than writing
 * anything. A fleet officer who wants to know why they were not warned has one place to look and
 * one schedule entry to check, rather than two that can be enabled separately and then silently
 * disagree about which tenants were swept.
 *
 * IT WRITES NOTHING, WHICH IS THE POINT. There is no `is_overdue` column, no expiry status and no
 * state anywhere that depends on this having run. Not running it for a week loses a week of
 * warnings; every register tab, every filter and every due-date badge stays exactly as correct as it
 * was, because all of them ask the database to compare a date to today. That asymmetry is the whole
 * design: a misconfigured cron must be able to cost a company its REMINDERS and must never be able
 * to make a lapsed insurance certificate look valid.
 *
 * SAFE TO RUN TWICE, but not free to: a second run inside the same day raises the same events again,
 * and the notification engine will deliver them again. Daily is the right cadence — these are
 * horizons measured in weeks, and a warning that arrives every hour is a warning people filter.
 *
 * TENANT BY TENANT, IN ORDER. Each organization's window is its own setting, so the sweep is run
 * inside that organization's context rather than across a global query — which also means the
 * global scope is doing its ordinary job throughout, instead of being switched off for a bulk read
 * (§75).
 */
class SweepAssetExpiries extends Command
{
    protected $signature = 'assets:sweep
                            {--organization= : Limit the sweep to one organization id}';

    protected $description = 'Warn about maintenance falling due and vehicle documents about to expire';

    public function handle(
        AssetMaintenanceService $maintenance,
        VehicleDocumentService $documents,
        OrganizationContext $context,
    ): int {
        $only = $this->option('organization');

        // Resolved without the tenant scope for the same reason SweepApprovalEscalations does it:
        // the console has no signed-in user, so there is no ambient tenant to compare against, and
        // the id came from an operator at a shell prompt rather than from a request.
        $organizations = $context->withoutScope(fn () => Organization::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get());

        if ($organizations->isEmpty()) {
            $this->error($only === null ? 'No organizations.' : 'No organization with id '.$only.'.');

            return self::FAILURE;
        }

        $due = 0;
        $expiring = 0;

        foreach ($organizations as $organization) {
            $id = (int) $organization->getKey();

            $context->within($organization, function () use ($maintenance, $documents, $id, &$due, &$expiring) {
                $due += $maintenance->sweepDue($id);
                $expiring += $documents->sweepExpiring($id);
            });
        }

        $this->info($due === 0 && $expiring === 0
            ? 'Nothing falls due inside the configured warning windows.'
            : 'Warned about '.$due.' maintenance job(s) and '.$expiring.' vehicle document(s).');

        return self::SUCCESS;
    }
}
