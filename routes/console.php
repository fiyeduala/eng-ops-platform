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
 * ONE ENTRY FOR EVERY REGISTER, not one per module. This replaced `assets:sweep`, which watched
 * maintenance due dates and vehicle paperwork because those were the two somebody had written a
 * sweep for; what each company watches is now its own configuration, so a company adding a watch on
 * its work-at-height permits adds a row rather than a schedule entry. A second line here would be
 * the beginning of the per-module sprawl the whole part exists to end.
 *
 * The sweep writes nothing (see SweepExpiries): if this line is deleted, or the cron is never
 * installed, the company loses its warnings and every expiry date on every screen stays exactly as
 * correct as it was.
 */
Schedule::command('expiry:sweep')
    ->dailyAt('06:30')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->description('Warn about the dates each company asked to be warned about');

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

/*
 * Hourly, not daily, and that needs explaining.
 *
 * Each company sets its own end of day (`visitor.auto_close_at`) because a head office closing at
 * six and a site running a night shift cannot share one. A daily schedule would mean picking a
 * single hour here and being wrong for everybody whose cut-off is not it — either sweeping before
 * their day ends, which signs out people who are still in the building, or hours after, which is the
 * stale roll call the sweep exists to prevent. Running every hour and letting the command decide
 * whether each organization's cut-off has passed puts the decision where the setting is.
 *
 * It writes, like movements:apply — but what it writes is an admission rather than a fact. See
 * CloseOutVisitors: everything it closes is marked as an assumed departure, never a witnessed one.
 */
Schedule::command('visitors:close-out')
    ->hourly()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->description('Sign out visitors still shown on site at the end of the day');

/*
 * Take the day's reading of every measure a company watches.
 *
 * 01:00, deliberately BEFORE the expiry sweep and after the movements one. A department's figures
 * should be counted against the organogram the day actually starts with, so a promotion effective
 * this morning has already landed when the measures are taken; and taking the reading before the
 * working day begins is what makes "yesterday's figure" mean a whole day rather than a partial one.
 *
 * Like expiry:sweep, this writes nothing but its own table (see SnapshotSweeper). A missing cron
 * costs trend points and leaves every live figure, list and badge exactly as correct as it was —
 * which is why it is safe to run unattended, and why it is not the thing dashboards depend on for
 * their current numbers.
 *
 * Still no second cron entry: `schedule:run` is the one line a company installs (§1).
 */
Schedule::command('kpi:snapshot')
    ->dailyAt('01:00')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->description('Record the day’s figure for every measure each company watches');

/*
 * The queue, run from the same one cron entry as everything else (§1).
 *
 * THIS IS NOT A DAEMON AND MUST NOT BECOME ONE. `--stop-when-empty` is what keeps the promise the
 * whole platform is built on: the worker starts, drains whatever is waiting, and exits. No
 * Supervisor, no systemd unit, no `nohup`, nothing for a company to notice is dead six weeks later.
 * A cPanel account that installed the single `schedule:run` line gets background work; one that did
 * not gets a platform where large exports sit at "Waiting" on a page that says so, which is a
 * missing feature rather than a broken system.
 *
 * `--max-time=55` is the important number. Every minute the scheduler fires this, so a worker that
 * outlives its minute is a worker overlapping the next one; 55 seconds leaves it dead before the
 * next fires, and `withoutOverlapping(2)` catches the case where it is not. Between them, at most
 * one worker per minute — which on shared hosting is the difference between a queue and a process
 * count complaint from the host.
 *
 * `--tries=1` here as well as on the job, because the queue's default of retrying forever is
 * precisely wrong for work that failed because it was too big: the retry is the same expensive
 * thing again, and the person has already been told it failed.
 *
 * WHAT IS ON THIS QUEUE IS EXPORTS AND NOTHING ELSE, today. Notifications are still sent inline,
 * deliberately — a notice that arrives when the cron feels like it is worse than one that costs the
 * request a few milliseconds, and it is the one thing that must work on an account with no cron at
 * all.
 */
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1 --quiet')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->description('Drain any background work that is waiting');

/*
 * Delete prepared export files once the company's retention has passed (§67).
 *
 * Hourly rather than daily because retention is set in HOURS — a company that asked for two and got
 * up to twenty-six would not have been given the setting it thought it was. Running hourly makes the
 * worst case an hour, which is honest against a control measured in hours.
 *
 * Unlike every other sweep on this list, the consequence of a missing cron here is a security one
 * rather than a lost convenience: an export is a frozen copy of one person's visibility, readable
 * long after the access that produced it is gone. SweepExports says more about why that is the one
 * thing on disk nobody is managing. The row survives the file either way, so the audit trail of what
 * was exported does not depend on this line running (§59).
 */
Schedule::command('exports:sweep')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->description('Delete prepared export files whose retention has passed');

/*
 * The daily executive report, offered to every company at the hour that company chose (§55).
 *
 * HOURLY, NOT DAILY, AND THAT IS THE WHOLE TRICK. `report.daily_executive_hour` is a per-company
 * setting, and a single daily cron would have to pick one time for every tenant on the host — which
 * would leave the setting on the screen doing nothing, the worst kind of configuration. Fired every
 * hour, the command asks each company whether this is its hour IN ITS OWN TIMEZONE and almost always
 * answers no. Twenty-three cheap passes buy one honest setting, and §1 still has its one cron entry.
 *
 * The command is idempotent per company per day, so the catch-up run a missed hour produces sends
 * nothing twice. `withoutOverlapping(20)` is generous because a run that is genuinely working is
 * composing a board and rendering a PDF per recipient; the lock is there to stop a slow hour being
 * joined by the next one, not to bound the work.
 */
Schedule::command('report:daily-executive')
    ->hourly()
    ->withoutOverlapping(20)
    ->runInBackground()
    ->description('Send each company’s daily executive report at its configured hour');
