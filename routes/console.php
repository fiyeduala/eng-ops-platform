<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Everything here runs from ONE cron entry, which is the whole point (§1). On cPanel that entry is:
|
|     * * * * * /usr/local/bin/php /home/USER/app/artisan schedule:run >> /dev/null 2>&1
|
| No Supervisor, no queue daemon, no Node process. If an organization never installs the cron, the
| platform still works — approvals simply never escalate, which is a missing feature rather than a
| broken system.
|
| `withoutOverlapping` matters more than it looks: on shared hosting a slow sweep and the next
| minute's cron will happily run at the same time, and two sweeps racing to escalate the same stage
| is how somebody gets four identical reminders. EscalationService is idempotent as well, because a
| lock file is not a guarantee.
*/

Schedule::command('approvals:sweep')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->description('Escalate overdue approval stages');

/*
 * Daily rather than hourly, and early rather than at midnight.
 *
 * These are horizons measured in weeks — a certificate expiring in six days does not become more
 * expired between 09:00 and 10:00, and an hourly reminder is a reminder people build a filter for.
 * 06:30 puts the warning in the inbox before the yard opens, which is when somebody can still stop
 * the truck.
 *
 * The sweep writes nothing (see SweepAssetExpiries): if this line is deleted, or the cron is never
 * installed, the company loses its warnings and every expiry date on every screen stays exactly as
 * correct as it was.
 */
Schedule::command('assets:sweep')
    ->dailyAt('06:30')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->description('Warn about maintenance due and vehicle documents expiring');

/*
 * The one scheduled job on this list that CHANGES data, which is why it is called out here.
 *
 * An approved promotion or transfer waits for its effective date; this is what writes it onto the
 * personnel record when the date arrives. Losing the cron for a week therefore costs a week of people
 * sitting in the wrong position and reporting to the wrong manager — not a week of missed reminders.
 * The command catches up on the next run rather than skipping what it missed, and running it by hand
 * is the intended recovery.
 *
 * 00:30 because a movement effective on the first of the month should be true when the first of the
 * month starts, and because that is the quietest half hour on a shared host.
 */
Schedule::command('movements:apply')
    ->dailyAt('00:30')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->description('Apply approved employee movements that have reached their effective date');
