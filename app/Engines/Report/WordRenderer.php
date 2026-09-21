<?php

declare(strict_types=1);

namespace App\Engines\Report;

use App\Models\ReportDefinition;
use App\Models\User;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * A report as an editable deliverable (docs/11 §3).
 *
 * WHY THIS EXISTS WHEN THERE IS ALREADY A PDF. The two are not the same document with a different
 * extension. A PDF is the record — fixed, printable, the thing attached to a transmittal. This is
 * the draft somebody is going to WRITE ON: a monthly progress table that goes into a client report
 * under the company's own covering text, a register of open items that becomes the body of a
 * meeting's minutes. Exporting it as a PDF means retyping it, and everything retyped is eventually
 * retyped wrongly.
 *
 * WHICH MAKES THE PROVENANCE LINE MORE IMPORTANT HERE, NOT LESS, AND IT IS THE DESIGN PROBLEM OF
 * THIS CLASS. Every other export carries "shows only what X is permitted to see" in a place that
 * travels with the file. This is the one format where the reader can delete that line, and will —
 * it is a draft, and tidying the top off a draft is the first thing anybody does. So the line is in
 * the page FOOTER, which is repeated on every page and survives pasting the table into another
 * document far more often than a paragraph does, and it is in the file's own properties as well.
 * Neither is a control. Both are the honest best available, and this comment is here so nobody
 * later mistakes the footer for a guarantee.
 *
 * LANDSCAPE, ALWAYS. A report is up to fifteen columns wide (ReportDefinition::MAX_COLUMNS) and a
 * portrait A4 table of fifteen columns is unreadable in a way that makes people export the CSV and
 * rebuild the table by hand, which is the outcome this format exists to prevent.
 *
 * It is built in memory like the workbook, and is subject to the same threshold and the same queue
 * for the same reason (§95).
 */
class WordRenderer
{
    /**
     * The bytes, not a file.
     *
     * Same division as the other three renderers: storing is the caller's decision, because the
     * small-export path deliberately never touches the disk (§67).
     */
    public function render(ReportDefinition $report, ReportResult $result, User $actor): string
    {
        $this->configure();

        $word = new PhpWord;

        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);

        $word->getDocInfo()
            ->setCreator($actor->name)
            ->setTitle($report->name)
            ->setDescription(trim(($report->description ? $report->description.' — ' : '')
                .'Shows only what '.$actor->name.' was permitted to see.'));

        $section = $word->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => Converter::cmToTwip(29.7),
            'pageSizeH' => Converter::cmToTwip(21.0),
            'marginTop' => Converter::cmToTwip(1.8),
            'marginBottom' => Converter::cmToTwip(1.8),
            'marginLeft' => Converter::cmToTwip(1.6),
            'marginRight' => Converter::cmToTwip(1.6),
        ]);

        $this->addFooter($section, $report, $actor);
        $this->addHeading($section, $report, $result, $actor);

        if ($result->isEmpty()) {
            $section->addText('Nothing matched.', ['italic' => true, 'color' => '667085']);

            return $this->bytes($word);
        }

        foreach ($result->groups as $group) {
            if ($group['label'] !== null) {
                // A real heading here, unlike the CSV and the workbook. Nobody sorts a Word
                // document by column B, so the objection that made the group a repeated cell in the
                // spreadsheet does not apply — and a heading is what somebody pasting one
                // department's section into a client report actually needs.
                $section->addText($group['label'], ['bold' => true, 'size' => 11], ['spaceBefore' => 240, 'spaceAfter' => 80]);
            }

            $this->addTable($section, $result, $group['rows']);
        }

        $section->addTextBreak();
        $section->addText(
            number_format($result->total).' '.($result->total === 1 ? 'row' : 'rows').'.',
            ['size' => 9, 'color' => '667085'],
        );

        return $this->bytes($word);
    }

    // ================================================================== the page

    private function addHeading(Section $section, ReportDefinition $report, ReportResult $result, User $actor): void
    {
        $section->addText($report->name, ['bold' => true, 'size' => 16], ['spaceAfter' => 60]);

        if ($report->description) {
            $section->addText((string) $report->description, ['size' => 10, 'color' => '667085'], ['spaceAfter' => 60]);
        }

        $section->addText(
            'Run '.($result->ranAt?->format('j M Y, H:i') ?? '')
                .' · '.$actor->name.'’s view — another reader may see a different set of rows.',
            ['size' => 9, 'color' => '667085'],
            ['spaceAfter' => 160],
        );

        if ($result->truncationNotice() !== null) {
            // Above the table, in red, and bold. A truncated list pasted into a client report is
            // the export mistake with the largest consequences, and it is invisible unless the
            // warning is somewhere the person copying the table cannot miss it.
            $section->addText(
                $result->truncationNotice(),
                ['bold' => true, 'color' => 'B42318', 'size' => 9],
                ['spaceAfter' => 160],
            );
        }
    }

    private function addFooter(Section $section, ReportDefinition $report, User $actor): void
    {
        $footer = $section->addFooter();

        $footer->addPreserveText(
            $this->escape($report->name).'  |  Printed '.now()->format('j M Y, H:i')
                .'  |  Shows only what '.$this->escape($actor->name).' is permitted to see.'
                .'  |  Page {PAGE} of {NUMPAGES}',
            ['size' => 8, 'color' => '667085'],
            ['alignment' => Jc::CENTER],
        );
    }

    /**
     * @param  array<int,array<string,string>>  $rows
     */
    private function addTable(Section $section, ReportResult $result, array $rows): void
    {
        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => 'D0D5DD',
            'cellMargin' => 60,
            'width' => 100 * 50,
            'unit' => 'pct',
        ]);

        $table->addRow(null, ['tblHeader' => true]);

        foreach ($result->columns as $heading) {
            $cell = $table->addCell(null, ['bgColor' => 'EEF2F7']);
            $cell->addText($this->escape($heading), ['bold' => true, 'size' => 9]);
        }

        foreach ($rows as $row) {
            $table->addRow();

            foreach (array_keys($result->columns) as $column) {
                $table->addCell()->addText($this->escape((string) ($row[$column] ?? '')), ['size' => 9]);
            }
        }
    }

    // ================================================================== plumbing

    /**
     * Company-entered text, made safe for a format that is XML underneath.
     *
     * PhpWord writes the string into the document's XML, and an ampersand or an angle bracket in a
     * document title produces a .docx that Word refuses to open with no useful message. Control
     * characters do the same. This is a correctness guard rather than a security one — there is no
     * formula to inject in a Word table — but a company whose report is called "Civil & Piping"
     * getting a file that will not open is the same outcome as no export at all.
     */
    private function escape(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    }

    /**
     * Word2007 writes to a path and only to a path.
     *
     * Unlike the workbook, there is no php://output route here, so a temporary file is unavoidable.
     * It goes to the system temp directory with a generated name, is read once, and is unlinked in
     * a `finally` so it goes even when the write throws — the same shape DocxExporter has used
     * since Phase 5. The exposure is real but bounded; the alternative is no DOCX export.
     */
    private function bytes(PhpWord $word): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'report_docx_');

        if ($temp === false) {
            throw new \RuntimeException('A temporary file could not be created for the export.');
        }

        try {
            (new Word2007($word))->save($temp);

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }
    }

    private function configure(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \DomainException(
                'Word export is unavailable: this server does not have the PHP zip extension enabled.'
            );
        }

        $temp = storage_path('framework/cache/phpword');

        if (! is_dir($temp)) {
            @mkdir($temp, 0o775, true);
        }

        if (is_writable($temp)) {
            PhpWordSettings::setTempDir($temp);
        }
    }
}
