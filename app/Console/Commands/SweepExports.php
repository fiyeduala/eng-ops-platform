<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Models\Export;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete prepared export files once their retention has passed (docs/11 §3, §67).
 *
 * THE FILE GOES AND THE ROW STAYS. That split is the whole design of the exports table, and it is
 * worth saying again where the deleting actually happens: what left the platform, who took it, with
 * which filters and how many rows, is an audit question that outlives anybody's need for the file
 * (§59). Deleting the row with the file would quietly erase the record of the export at the exact
 * moment the export stopped being observable any other way.
 *
 * A PREPARED EXPORT IS THE ONE THING HERE NOBODY IS MANAGING. Every other copy of company data on
 * disk belongs to a document with a category, an owner and a review date. This is a frozen extract
 * of one person's visibility, sitting in storage, still readable long after the access that produced
 * it was withdrawn. Retention is not housekeeping — it is the only thing that stops an export being
 * a permanent bypass of the permission that created it.
 *
 * IT RUNS UNSCOPED AND WRITES ONLY ITS OWN TABLE, like the other sweeps. There is no signed-in user
 * at a cron, so there is no ambient tenant; the row carries its own organization and the path was
 * generated per-tenant, so nothing here has to reason about whose file it is holding (§75). A cron
 * that is never installed costs disk space and keeps files longer than the company asked, which is
 * visible on the exports page — it does not corrupt anything.
 */
class SweepExports extends Command
{
    protected $signature = 'exports:sweep
                            {--organization= : Limit the sweep to one organization id}';

    protected $description = 'Delete prepared export files whose retention has passed';

    public function handle(OrganizationContext $context): int
    {
        $only = $this->option('organization');

        // Chunked rather than loaded whole: a company that exports heavily for a week leaves
        // thousands of rows behind, and a sweep that needs all of them in memory at once is a cron
        // that dies on the host it was written for (§95).
        $swept = 0;
        $missing = 0;
        $freed = 0;

        $disk = Storage::disk(Export::DISK);

        $context->withoutScope(function () use ($only, $disk, &$swept, &$missing, &$freed): void {
            Export::query()
                // Only `ready`. A queued or running export has no retention date yet — its file does
                // not exist — and a failed or already-expired one has nothing left to delete. A row
                // stuck at "Waiting" because no queue worker ever ran is deliberately left alone: it
                // is the visible symptom of a missing cron entry, and sweeping it would hide that.
                ->where('status', 'ready')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->when($only !== null, fn ($q) => $q->where('organization_id', (int) $only))
                ->orderBy('id')
                ->chunkById(100, function ($exports) use ($disk, &$swept, &$missing, &$freed): void {
                    foreach ($exports as $export) {
                        if ($export->path !== null) {
                            if ($disk->exists($export->path)) {
                                $freed += (int) $export->bytes;
                                $disk->delete($export->path);
                            } else {
                                // Already gone — a manual clear-out, or a restore that did not carry
                                // storage. Counted separately because "swept 40, 40 of them already
                                // missing" is the shape of a backup problem, and a sweep that
                                // reported them as deleted would hide it.
                                $missing++;
                            }
                        }

                        $export->forceFill([
                            'status' => 'expired',
                            'path' => null,
                            'bytes' => null,
                        ])->save();

                        $swept++;
                    }
                });
        });

        if ($swept === 0) {
            $this->info('No export files have passed their retention.');

            return self::SUCCESS;
        }

        $this->info('Deleted '.$swept.' export file(s), freeing '.$this->readable($freed).'.');

        if ($missing > 0) {
            $this->warn($missing.' of them were already missing from storage.');
        }

        return self::SUCCESS;
    }

    /** Bytes as something a person reads in a cron log. */
    private function readable(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' bytes';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
