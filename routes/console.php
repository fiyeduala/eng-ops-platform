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
