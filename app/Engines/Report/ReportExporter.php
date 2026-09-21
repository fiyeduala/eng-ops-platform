<?php

declare(strict_types=1);

namespace App\Engines\Report;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Document\PdfRenderer;
use App\Models\ReportDefinition;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report, taken off the screen (docs/11 §3, §59, §67).
 *
 * NOTHING THIS CLASS PRODUCES IS WRITTEN TO DISK, AND THAT IS THE SECURITY DECISION HERE. §67
 * requires stored files to live on private storage behind an authorized, validated download route.
 * The strongest way to satisfy a rule about stored files is not to store one: both formats below are
 * generated inside the authenticated request that asked for them and streamed straight back. There
 * is no artefact on the disk to be found by path-guessing, no orphan to sweep up, no window between
 * writing and deleting, and no second route that has to re-derive who may read it.
 *
 * THAT REMAINS THE PREFERRED PATH AND IS STILL THE ONE MOST EXPORTS TAKE. Phase 8 added a second,
 * for the exports that cannot finish inside a request: ExportManager stores those on the private
 * disk behind ExportPolicy, with a retention date and a sweep. The threshold between the two is a
 * company setting, and it is deliberately set so that the stored path is the exception — every file
 * that path produces is a copy of one person's visibility that somebody has to remember to delete.
 *
 * THE ROWS ARE ALREADY THE VIEWER'S. This class never queries anything. It is handed a ReportResult
 * that ReportRunner produced through one person's `visibleTo()`, and it formats it. That division is
 * deliberate — an exporter that could fetch its own rows would be a second path to the data with its
 * own chance of forgetting the scope, which is precisely the "second approval path" mistake §78
 * warns about, transplanted into reporting.
 *
 * EVERY EXPORT IS AUDITED WITH ITS FILTER SET AND ROW COUNT (§59). Not "somebody exported a report" —
 * which question it asked, how many rows it returned, and whether the list was cut short. The last
 * of those is what makes an old export reconcilable: a spreadsheet found in an email two years later
 * is only evidence if the trail can say what it contained.
 *
 * LARGE EXPORTS GO THROUGH ExportManager, which counts the rows before building any of them and
 * hands anything over the company's threshold to a job. What has NOT changed is the truncation
 * notice: ReportDefinition::MAX_ROWS still caps the list, and the notice still travels with the
 * file in every format, because a truncated export that did not announce itself is worse than a
 * refused one — somebody totals it.
 */
class ReportExporter
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * A spreadsheet.
     *
     * STREAMED, so a five-thousand-row report never becomes a five-thousand-row string in memory on
     * a shared host (§95). The rows are already rendered strings by the time they arrive here.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     */
    public function csv(
        ReportDefinition $report,
        ReportResult $result,
        User $actor,
        array $extraFilters = [],
    ): StreamedResponse {
        $this->audit($report, $result, $actor, 'csv', $extraFilters);

        $filename = $this->filename($report, 'csv');

        return new StreamedResponse(function () use ($report, $result, $actor) {
            $handle = fopen('php://output', 'wb');

            $this->writeCsv($handle, $report, $result, $actor);

            fclose($handle);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',

            // An export is one person's view of the data at one moment. Anything that caches it —
            // a browser, a corporate proxy — is a copy served to whoever asks next (§61).
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * The body of a CSV, written to any handle.
     *
     * PUBLIC AND SEPARATE FROM THE RESPONSE SINCE PHASE 8, because a large export is prepared in the
     * background and written to a file rather than streamed to a browser. Both paths call this, so
     * there is exactly one place that decides what a CSV of a report contains — a second
     * implementation for the queued path would be a file that disagreed with the one the same report
     * downloads directly, which is the failure docs/11 §1 is about.
     *
     * @param  resource  $handle
     */
    public function writeCsv($handle, ReportDefinition $report, ReportResult $result, User $actor): void
    {
        // A BOM, because the overwhelming majority of these are opened in Excel on Windows,
        // and without it Excel reads UTF-8 as the system codepage — which turns every accented
        // name in the company into mojibake and makes the export look broken rather than the
        // spreadsheet look misconfigured.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [$report->name]);

        if ($report->description) {
            fputcsv($handle, [$report->description]);
        }

        fputcsv($handle, ['Run '.($result->ranAt?->format('j M Y, H:i') ?? '')]);

        // WHOSE VIEW THIS IS, in the file, in the same words the PDF footer uses. The rows
        // below are one person's — a department manager's export of a company report is a
        // department's worth of rows — and the file is read by people with no way to ask.
        // Without this line, two colleagues comparing two exports of one report conclude the
        // platform miscounts rather than that they have different reach (§61).
        fputcsv($handle, ['Shows only what '.$actor->name.' is permitted to see.']);

        // The notice goes at the TOP of the file, not the bottom. A spreadsheet is read from
        // row one and totalled from the bottom, so a warning after the last row is a warning
        // under the sum nobody scrolled past.
        if ($result->truncationNotice() !== null) {
            fputcsv($handle, [$result->truncationNotice()]);
        }

        fputcsv($handle, []);

        $grouped = $result->isGrouped();
        $headings = array_values($result->columns);

        fputcsv($handle, $grouped ? array_merge(['Group'], $headings) : $headings);

        foreach ($result->groups as $group) {
            foreach ($group['rows'] as $row) {
                $cells = [];

                foreach (array_keys($result->columns) as $column) {
                    $cells[] = $this->safeCell((string) ($row[$column] ?? ''));
                }

                // The group on every row rather than as a heading: a CSV is sorted and
                // filtered by whoever opens it, and a heading row becomes a stray line of
                // text in the middle of the data the first time somebody sorts by column B.
                fputcsv($handle, $grouped ? array_merge([$group['label'] ?? ''], $cells) : $cells);
            }
        }
    }

    /**
     * A printable copy, through the renderer the document module already uses.
     *
     * Reused rather than reimplemented for the reason that renderer documents at length: Dompdf is
     * chosen because §1 targets cPanel, where a headless browser cannot be installed. A second PDF
     * path here would be a second set of those constraints to get wrong.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     */
    public function pdf(
        ReportDefinition $report,
        ReportResult $result,
        User $actor,
        array $extraFilters = [],
    ): Response {
        $this->audit($report, $result, $actor, 'pdf', $extraFilters);

        return new Response($this->pdfBytes($report, $result, $actor), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($report, 'pdf').'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * The PDF itself, as bytes.
     *
     * Split out for the same reason writeCsv() was: the background path stores a file and the
     * direct path streams a response, and both must produce the identical document. One method,
     * two callers, no chance of the queued copy carrying a different footer from the direct one.
     */
    public function pdfBytes(ReportDefinition $report, ReportResult $result, User $actor): string
    {
        return $this->pdf->renderHtml(
            title: $report->name,
            bodyHtml: $this->html($report, $result, $actor),
            footerHtml: e($report->name).' &nbsp;|&nbsp; Printed '.now()->format('j M Y, H:i')
                .' &nbsp;|&nbsp; Shows only what '.e($actor->name).' is permitted to see.',
        );
    }

    /**
     * The name this report's file is saved under, in a given format.
     *
     * Public since Phase 8 so a queued export stores the same stem the direct download would have
     * offered. See the private implementation for why it is stripped rather than escaped (§84).
     */
    public function filenameFor(ReportDefinition $report, string $extension): string
    {
        return $this->filename($report, $extension);
    }

    /**
     * §59 for an export that was prepared in the background.
     *
     * The same entry the direct path writes, at the moment the file actually exists — which is the
     * only moment the row count is known. A queued export that never ran therefore has no audit
     * entry, and correctly so: nothing left the building. The `exports` row carries the attempt.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     */
    public function recordPreparedExport(
        ReportDefinition $report,
        ReportResult $result,
        User $actor,
        string $format,
        array $extraFilters = [],
    ): void {
        $this->audit($report, $result, $actor, $format, $extraFilters);
    }

    // ================================================================== the page

    private function html(ReportDefinition $report, ReportResult $result, User $actor): string
    {
        $html = '<h1>'.e($report->name).'</h1>';

        if ($report->description) {
            $html .= '<p class="muted">'.e($report->description).'</p>';
        }

        $html .= '<p class="muted">Run '.e($result->ranAt?->format('j M Y, H:i') ?? '')
            // Printed on the document itself, because a PDF outlives the screen it came from and
            // gets forwarded to people who will otherwise read it as the company-wide figure.
            .' &middot; '.e($actor->name).'’s view — another reader may see a different set of rows.</p>';

        if ($result->truncationNotice() !== null) {
            $html .= '<p class="danger"><strong>'.e($result->truncationNotice()).'</strong></p>';
        }

        if ($result->isEmpty()) {
            return $html.'<p><em>Nothing matched.</em></p>';
        }

        foreach ($result->groups as $group) {
            if ($group['label'] !== null) {
                $html .= '<h2>'.e($group['label']).'</h2>';
            }

            $html .= '<table><thead><tr>';

            foreach ($result->columns as $heading) {
                $html .= '<th>'.e($heading).'</th>';
            }

            $html .= '</tr></thead><tbody>';

            foreach ($group['rows'] as $row) {
                $html .= '<tr>';

                foreach (array_keys($result->columns) as $column) {
                    $html .= '<td>'.e((string) ($row[$column] ?? '')).'</td>';
                }

                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        return $html.'<p class="muted">'.number_format($result->total).' '
            .($result->total === 1 ? 'row' : 'rows').'.</p>';
    }

    // ================================================================== the trail

    /**
     * §59: what was asked, by whom, and how much came back.
     *
     * Recorded BEFORE the file is streamed rather than after. A streamed response's body is
     * generated after the framework has handed off, so code placed after the return would run with
     * the connection possibly already gone — and an export whose audit entry depends on the
     * download completing is an export that can be taken without a trace by cancelling it.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $extraFilters
     */
    private function audit(
        ReportDefinition $report,
        ReportResult $result,
        User $actor,
        string $format,
        array $extraFilters,
    ): void {
        $this->activity->record(
            $report,
            'exported',
            $actor->name.' exported “'.$report->name.'” as '.mb_strtoupper($format).' — '
                .number_format($result->total).' '.($result->total === 1 ? 'row' : 'rows').'.',
            [
                'format' => $format,
                'rows' => $result->total,
                'truncated' => $result->truncated,
                'entity_type' => $report->entity_type,

                // Both filter sets, kept apart. "Which conditions were stored on the report" and
                // "which did this person add on the day" are different questions when somebody
                // asks two years later why one export disagrees with another.
                'filters' => $report->baseFilters(),
                'ad_hoc_filters' => $extraFilters,
            ],
            $actor,
        );
    }

    // ================================================================== naming

    /**
     * A filename that is safe to put in a header and safe to save.
     *
     * Stripped rather than escaped. A report name is company-entered text, and a quote or a newline
     * inside a `Content-Disposition` header is header injection; a slash or `..` inside the
     * filename is a path the browser will interpret when saving (§84).
     */
    private function filename(ReportDefinition $report, string $extension): string
    {
        $stem = preg_replace('/[^A-Za-z0-9 \-_]/', '', $report->name);
        $stem = trim(preg_replace('/\s+/', ' ', (string) $stem));

        return mb_substr($stem === '' ? 'report' : $stem, 0, 100).'-'.now()->format('Y-m-d').'.'.$extension;
    }

    /**
     * Stop a spreadsheet treating a cell as a formula.
     *
     * CSV INJECTION, and the one genuinely dangerous thing about exporting user-entered text. A
     * cell beginning `=`, `+`, `-` or `@` is evaluated by Excel and LibreOffice on open, and
     * `=HYPERLINK(...)` or a DDE call in a document title becomes code running on the machine of
     * whoever opened the export — somebody who never visited this platform and had no chance to
     * distrust it. A leading apostrophe makes the cell text, which is what it always was.
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
