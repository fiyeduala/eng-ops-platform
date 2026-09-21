<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Engines\Report\ExportManager;
use App\Models\Export;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Build a file somebody asked for, and tell them when it is there (docs/11 §3).
 *
 * THE PAYLOAD IS AN ID, NOT A MODEL, AND THAT IS DELIBERATE. Laravel's SerializesModels would
 * re-resolve an Export on the other side, and re-resolving happens outside any tenant context — so
 * the difference between passing the id and passing the model is the difference between a lookup
 * this class can guard and one the framework performs before this class runs. Everything the job
 * needs is on the row, and `OrganizationContext::withoutScope()` is the ONE place the lookup
 * happens, once, explicitly (§75).
 *
 * THE QUEUE RUNS FROM THE SAME SINGLE CRON ENTRY AS EVERYTHING ELSE (§1). There is no Supervisor,
 * no daemon and no Redis: `routes/console.php` schedules `queue:work --stop-when-empty` every
 * minute, so a queued export is picked up within about a minute and the worker exits. That is why
 * this job must be safe to run late, safe to run twice, and safe never to run at all — a company
 * that never installed the cron sees its large exports sit at "Waiting", which is visible on the
 * exports page and is a missing feature rather than a broken one.
 *
 * ONE ATTEMPT. `$tries = 1` because a failed export is almost always a report that cannot be run or
 * a host that cannot build the file, and neither improves on the second go — what retrying actually
 * produces is two full report runs and two workbooks in memory on a shared host, which is the
 * failure mode the queue exists to avoid. The person is told it failed and can ask again, which is
 * a retry with somebody's judgement in it.
 */
class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Generous, because it is a ceiling rather than an expectation.
     *
     * The whole point of this path is that the work does not fit in a web request; a limit tight
     * enough to be "safe" would simply move the timeout. Ten minutes is longer than any export
     * capped at ReportDefinition::MAX_ROWS should ever take, and short enough that a stuck job
     * releases the lock before the next cron run.
     */
    public int $timeout = 600;

    public function __construct(private readonly int $exportId) {}

    public function handle(
        ExportManager $manager,
        OrganizationContext $context,
        NotificationDispatcher $notifications,
    ): void {
        // The one unscoped lookup, explained above. Nothing else in this class reads across tenants.
        $export = $context->withoutScope(fn () => Export::query()
            ->with(['report', 'requester.organization'])
            ->find($this->exportId));

        if ($export === null) {
            // Deleted between the click and the worker — somebody changed their mind, or the
            // requester's account was closed. Not an error.
            return;
        }

        if ($export->status !== 'queued') {
            // Already run, or already failed. `queue:work --stop-when-empty` under a cron can
            // overlap on a slow host, and an export produced twice would write a second file and
            // leave the first orphaned on disk with nothing pointing at it (§67).
            return;
        }

        $export->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $failure = $manager->produce($export);
        } catch (Throwable $e) {
            // Logged with the export's id and NOT with its contents. An exception thrown while
            // rendering a report can carry row data in its message, and a log file is read by
            // people who hold no permission over any of it (§68).
            Log::error('An export could not be produced.', [
                'export_id' => $export->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $failure = 'Something went wrong while preparing this file. Try again, or narrow the report first.';
        }

        $failure === null
            ? $this->announceReady($export->refresh(), $notifications)
            : $this->announceFailure($export, $failure, $notifications);
    }

    /**
     * Tell the one person who asked.
     *
     * THE LINK IS SIGNED AND EXPIRING, and it is still not what authorizes the download. The route
     * sits behind `auth` and ExportPolicy as well, so a signed URL forwarded to a colleague is a
     * 403 rather than a file (§67, §84). The signature's job is narrower: it means a link that
     * leaves this notification stops working on its own, rather than remaining valid for as long as
     * the row exists.
     *
     * THE NOTICE CARRIES NO DATA FROM THE REPORT — not a row, not a total, not the filters. Only the
     * report's name, which the person chose to export and already knows (§68).
     */
    private function announceReady(Export $export, NotificationDispatcher $notifications): void
    {
        if (! $export->isCollectable()) {
            return;
        }

        $notifications->dispatch(
            NotificationMessage::make(
                key: 'export.ready',
                title: '“'.$export->name.'” is ready to download',
                body: 'The file you asked for has finished preparing. It will be available until '
                    .$export->expires_at?->format('j M Y, H:i').', after which it is deleted.',
                actionUrl: URL::temporarySignedRoute(
                    'exports.download',
                    $export->expires_at,
                    ['export' => $export->getKey()],
                ),
            ),
            Audience::user($export->requested_by),
            $export,
            'view',
        );
    }

    private function announceFailure(Export $export, string $reason, NotificationDispatcher $notifications): void
    {
        $export->forceFill([
            'status' => 'failed',
            'failure_reason' => $reason,
            'completed_at' => now(),
        ])->save();

        $notifications->dispatch(
            NotificationMessage::make(
                key: 'export.failed',
                title: '“'.$export->name.'” could not be exported',
                body: $reason,
                actionUrl: route('exports.index'),
            ),
            Audience::user($export->requested_by),
            $export,
            'view',
        );
    }

    /**
     * The queue gave up on it — a timeout, or a worker killed mid-run.
     *
     * Marked failed here rather than left at `running` forever, because a row stuck at "Being
     * prepared" is a person who will keep the page open all afternoon. The guard against double
     * work in handle() reads the status, so this also makes the row safe to retry by hand.
     */
    public function failed(?Throwable $e): void
    {
        $export = app(OrganizationContext::class)->withoutScope(
            fn () => Export::query()->find($this->exportId)
        );

        if ($export === null || ! $export->isPending()) {
            return;
        }

        $export->forceFill([
            'status' => 'failed',
            'failure_reason' => 'This file took too long to prepare. Narrow the report with a filter and try again.',
            'completed_at' => now(),
        ])->save();
    }
}
