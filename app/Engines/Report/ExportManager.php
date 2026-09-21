<?php

declare(strict_types=1);

namespace App\Engines\Report;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\SettingsRepository;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The decision between handing a file over now and preparing it in the background (docs/11 §3).
 *
 * THE THRESHOLD IS ABOUT THE HOST, NOT ABOUT THE DATA, which is why it is a company setting rather
 * than a constant. §1 targets cPanel: `max_execution_time` is commonly 30 seconds and
 * `memory_limit` 128–256MB, and neither is ours to raise. A three-hundred-row workbook is
 * instantaneous there; a five-thousand-row one is a white page after thirty seconds with no
 * indication of what went wrong and no file. The same company on a VPS has no such limit. So the
 * platform does not guess — `report.export_queue_threshold` is set once per company and the
 * decision follows from it.
 *
 * THE ROWS ARE COUNTED BEFORE ANY ARE BUILT, and this is the part that would be easy to get
 * uselessly wrong. Running the report to find out whether running the report will time out is not a
 * safeguard, it is the timeout with extra steps. `ReportRunner::countUpTo()` asks the same query for
 * at most threshold-plus-one ids and nothing else.
 *
 * THE BACKGROUND PATH RE-RUNS THE REPORT AS THE REQUESTER, inside their organization, and never
 * reuses a result computed anywhere else. That is not caution about staleness; it is the only way
 * the rows in the file are the requester's own. A job that accepted a ReportResult from the web
 * request would be carrying one person's rows through a serialized payload into a process with no
 * session, which is a second way to get visibility right and therefore a second way to get it wrong
 * (§61, §78's argument applied to reporting).
 *
 * WHAT THIS CLASS DOES NOT DECIDE is whether the person may export at all. ReportDefinitionPolicy's
 * `export` ability is checked by the controller with the report in hand, before anything here runs.
 */
class ExportManager
{
    /**
     * Fallbacks for a company whose settings were never provisioned.
     *
     * Deliberately conservative: a platform that cannot read its own configuration should queue
     * sooner and keep files for less time, not more.
     */
    public const DEFAULT_THRESHOLD = 500;

    public const DEFAULT_RETENTION_HOURS = 12;

    public function __construct(
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly ExcelRenderer $excel,
        private readonly WordRenderer $word,
        private readonly SettingsRepository $settings,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * Above this many rows, a file is prepared in the background.
     *
     * Clamped to the report ceiling. A company that set the threshold above MAX_ROWS would have
     * turned the background path off without meaning to, because no report can ever return more
     * rows than the ceiling allows — and the setting would read as though it were still in force.
     */
    public function threshold(?int $organizationId = null): int
    {
        $configured = $this->settings->int(
            'report.export_queue_threshold',
            self::DEFAULT_THRESHOLD,
            $organizationId,
        );

        return max(1, min($configured, ReportDefinition::MAX_ROWS));
    }

    /** How long a prepared file stays on disk. */
    public function retentionHours(?int $organizationId = null): int
    {
        return max(1, $this->settings->int(
            'report.export_retention_hours',
            self::DEFAULT_RETENTION_HOURS,
            $organizationId,
        ));
    }

    // ================================================================== the decision

    /**
     * Either the file, or the promise of it.
     *
     * A Response means it is already downloading. An Export means it is being prepared and the
     * person will be told. Null means the report could not be run at all, and the caller re-runs it
     * to get the proper reason rather than this method inventing one — there is one wording for
     * "this report cannot run", and it lives on ReportResult.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $adHoc
     */
    public function offer(
        ReportDefinition $report,
        User $actor,
        string $format,
        array $adHoc = [],
    ): Response|Export|null {
        $threshold = $this->threshold((int) $actor->organization_id);
        $rows = $this->runner->countUpTo($report, $actor, $threshold, $adHoc);

        if ($rows === null) {
            return null;
        }

        return $rows > $threshold
            ? $this->queue($report, $actor, $format, $adHoc, $rows)
            : $this->immediate($report, $actor, $format, $adHoc);
    }

    /**
     * Small enough: run it, render it, stream it, store nothing.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $adHoc
     */
    private function immediate(
        ReportDefinition $report,
        User $actor,
        string $format,
        array $adHoc,
    ): ?Response {
        $result = $this->runner->run($report, $actor, $adHoc);

        if (! $result->available) {
            return null;
        }

        if ($format === 'csv') {
            return $this->exporter->csv($report, $result, $actor, $adHoc);
        }

        if ($format === 'pdf') {
            return $this->exporter->pdf($report, $result, $actor, $adHoc);
        }

        // The two new formats have no streaming form — both are built whole before a byte exists —
        // so they are audited here and returned as one body, exactly as the PDF is.
        $this->exporter->recordPreparedExport($report, $result, $actor, $format, $adHoc);

        $bytes = $format === 'xlsx'
            ? $this->excel->render($report, $result, $actor)
            : $this->word->render($report, $result, $actor);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => Export::MIME_TYPES[$format] ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$this->exporter->filenameFor($report, $format).'"',
            'Content-Length' => (string) strlen($bytes),

            // One person's view at one moment; a proxy that caches it serves it to whoever asks
            // next (§61).
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Too large: record the request, hand it to the queue, tell the caller to say so.
     *
     * `$rows` is the bounded count, which is threshold+1 at most — so it is NOT written to the row
     * as the row count. What ends up in `rows` is what the job actually produced. A number here
     * that said "501" for a twelve-thousand-row report would be a lie in an audit column.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $adHoc
     */
    private function queue(
        ReportDefinition $report,
        User $actor,
        string $format,
        array $adHoc,
        int $rows,
    ): Export {
        $export = Export::create([
            // §74: never from input, always from the actor.
            'organization_id' => $actor->organization_id,
            'requested_by' => $actor->getKey(),
            'report_definition_id' => $report->getKey(),

            // Copied rather than read through the relation, so the row still reads correctly after
            // the report is renamed or deleted.
            'name' => $report->name,
            'entity_type' => $report->entity_type,
            'format' => $format,
            'status' => 'queued',
            'filters' => $report->baseFilters(),
            'ad_hoc_filters' => $adHoc,
            'filename' => $this->exporter->filenameFor($report, $format),
        ]);

        GenerateExport::dispatch($export->getKey());

        return $export;
    }

    // ================================================================== the background path

    /**
     * Produce the file for a queued export.
     *
     * Called only by the job. Everything it needs is on the row, and the two things it will not take
     * from anywhere else are the organization and the requester — the whole run happens inside that
     * tenant, as that person, so the rows are theirs (§74, §75).
     *
     * @return string|null the failure, in words, or null on success
     */
    public function produce(Export $export): ?string
    {
        $report = $export->report;

        if ($report === null) {
            return 'The report this export came from has been deleted.';
        }

        $requester = $export->requester;

        if ($requester === null) {
            return 'The person who asked for this export no longer has an account.';
        }

        $result = $this->context->within(
            $requester->organization,
            fn () => $this->runner->run($report, $requester, $export->ad_hoc_filters ?? []),
        );

        if (! $result->available) {
            return $result->reason ?? 'This report could not be run.';
        }

        $bytes = match ($export->format) {
            'xlsx' => $this->excel->render($report, $result, $requester),
            'docx' => $this->word->render($report, $result, $requester),
            'pdf' => $this->exporter->pdfBytes($report, $result, $requester),
            'csv' => $this->csvBytes($report, $result, $requester),
            default => null,
        };

        if ($bytes === null) {
            return 'That export format is no longer offered.';
        }

        // A GENERATED PATH, never built from the report's name. The company-entered title is the
        // display filename and only that; a name containing `../` or a null byte is a path
        // traversal the moment it is concatenated (§67, §84).
        $path = 'exports/'.$export->organization_id.'/'.Str::uuid()->toString().'.'.$export->format;

        Storage::disk(Export::DISK)->put($path, $bytes);

        // The audit entry goes here rather than at request time, because only now is the row count
        // known — and the row count is the part of §59 that makes an old export reconcilable.
        $this->exporter->recordPreparedExport($report, $result, $requester, $export->format, $export->ad_hoc_filters ?? []);

        $export->forceFill([
            'status' => 'ready',
            'path' => $path,
            'bytes' => strlen($bytes),
            'rows' => $result->total,
            'truncated' => $result->truncated,
            'completed_at' => now(),
            'expires_at' => now()->addHours($this->retentionHours((int) $export->organization_id)),
            'failure_reason' => null,
        ])->save();

        return null;
    }

    /**
     * A CSV as one string, through the same writer the streamed download uses.
     *
     * php://temp rather than a real temporary file: it stays in memory up to a couple of megabytes
     * and spills to disk beyond that, which is the right trade for the one format here that can be
     * genuinely large. Nothing named or guessable is created either way.
     */
    private function csvBytes(ReportDefinition $report, ReportResult $result, User $actor): string
    {
        $handle = fopen('php://temp', 'w+b');

        try {
            $this->exporter->writeCsv($handle, $report, $result, $actor);

            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
