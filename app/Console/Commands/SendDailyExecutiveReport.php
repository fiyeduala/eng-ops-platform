<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Report\ExecutiveBriefing;
use App\Models\Organization;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send each company its daily executive report, at the hour that company chose (§55).
 *
 * RUNS EVERY HOUR AND SENDS ALMOST NEVER. The scheduler has one entry, fired hourly, and this
 * command decides per company whether this is that company's hour — in that company's own timezone,
 * not the server's. Putting the hour in the cron instead would give every tenant on the host the
 * same send time and make `report.daily_executive_hour` a setting that does nothing, which is worse
 * than not offering it (§1: one cron entry; §73: the setting is the interface).
 *
 * IT IS IDEMPOTENT BECAUSE THE SCHEDULER IS NOT TRUSTWORTHY. A cPanel cron that missed an hour
 * catches up by running twice, a hosting migration replays a day, and an administrator runs this by
 * hand to see what it does. Each recipient's briefing is keyed by the day it is about, so a second
 * run for the same day finds the row already there and sends nothing — the same rule docs/11 §7
 * sets for the escalation and expiry engines, for the same reason: a report delivered twice is a
 * report people stop opening.
 *
 * ONE COMPANY'S FAILURE DOES NOT STOP THE NEXT COMPANY'S. Each tenant is wrapped on its own, and a
 * briefing that throws is logged and counted rather than propagated — otherwise one organization
 * with a broken KPI definition silently cancels the morning report for every other tenant on the
 * host, and nobody finds out, because what they notice is an email that did not arrive.
 */
class SendDailyExecutiveReport extends Command
{
    protected $signature = 'report:daily-executive
                            {--organization= : Limit the run to one organization id}
                            {--as-of= : Produce the report for this moment instead of now}
                            {--force : Send even outside the configured hour, and replace today’s copy}';

    protected $description = 'Send each company’s daily executive report at its configured hour';

    public function handle(
        ExecutiveBriefing $briefing,
        OrganizationContext $context,
        SettingsRepository $settings,
    ): int {
        $only = $this->option('organization');
        $force = (bool) $this->option('force');

        try {
            $now = $this->option('as-of') === null
                ? Carbon::now()
                : Carbon::parse((string) $this->option('as-of'));
        } catch (\Throwable) {
            $this->error('Could not read “'.$this->option('as-of').'” as a moment.');

            return self::FAILURE;
        }

        // Resolved outside the tenant scope for the reason every sweep here gives: a console has no
        // signed-in user and therefore no ambient tenant, and the id came from an operator at a
        // shell prompt rather than from a request (§74 does not apply to an argument nobody can
        // forge, but the scope still has to be stepped around deliberately rather than by accident).
        $organizations = $context->withoutScope(fn () => Organization::query()
            ->when($only !== null, fn ($query) => $query->whereKey((int) $only))
            ->orderBy('id')
            ->get());

        if ($organizations->isEmpty()) {
            $this->error($only === null ? 'No organizations.' : 'No organization with id '.$only.'.');

            return self::FAILURE;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($organizations as $organization) {
            $id = (int) $organization->getKey();

            if (! $briefing->isEnabled($id)) {
                continue;
            }

            $local = $now->copy()->setTimezone(
                $settings->string('general.timezone', config('app.timezone'), $id),
            );

            if (! $force && $local->hour !== $briefing->hour($id)) {
                continue;
            }

            try {
                [$organizationSent, $organizationSkipped] = $context->within(
                    $organization,
                    fn () => $this->sendFor($briefing, $local, $force),
                );

                $sent += $organizationSent;
                $skipped += $organizationSkipped;
            } catch (\Throwable $e) {
                $failed++;

                // Identifier and message only. A briefing's contents are one person's figures, and
                // a log file is read by whoever administers the host rather than by whoever holds
                // the permission (§68 applied to the place nobody thinks of).
                Log::error('Daily executive report failed for one organization.', [
                    'organization_id' => $id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $this->error('Organization '.$id.': '.$e->getMessage());
            }
        }

        $this->info($sent === 0 && $skipped === 0
            ? 'Nothing to send this hour.'
            : 'Sent '.$sent.', skipped '.$skipped.'.');

        // Still SUCCESS when one tenant failed: the command did its job for the rest, and a
        // non-zero exit from an hourly cron mails the host administrator about somebody else's
        // misconfigured board every hour until it is fixed.
        return self::SUCCESS;
    }

    /**
     * Every recipient in the tenant that is already bound.
     *
     * @return array{int,int} sent, skipped
     */
    private function sendFor(ExecutiveBriefing $briefing, CarbonInterface $asOf, bool $force): array
    {
        $recipients = $briefing->recipients();

        if (count($recipients) > ExecutiveBriefing::MAX_RECIPIENTS) {
            // Said out loud rather than truncated. A company where forty people hold
            // `dashboard.view_executive` has a permissions problem, and a report that quietly sent
            // to the first forty would hide it behind an alphabetical cut-off nobody could see.
            $this->warn(count($recipients).' people hold the executive board permission, which is '
                .'more than the '.ExecutiveBriefing::MAX_RECIPIENTS.' this report will produce in '
                .'one run. Nothing was sent — narrow the permission or raise the ceiling.');

            return [0, count($recipients)];
        }

        $sent = 0;
        $skipped = 0;

        foreach ($recipients as $recipient) {
            $export = $briefing->briefingFor($recipient, $asOf, $force);

            if ($export === null) {
                $skipped++;

                continue;
            }

            $briefing->announce($export, $recipient, $asOf);
            $sent++;
        }

        return [$sent, $skipped];
    }
}
