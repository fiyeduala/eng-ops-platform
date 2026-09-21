<?php

declare(strict_types=1);

namespace App\Engines\Report;

use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\KpiCatalogue;
use App\Support\ReportCatalogue;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A report as a real workbook (docs/11 §3).
 *
 * THE DIFFERENCE BETWEEN THIS AND THE CSV IS TYPES, AND TYPES ARE THE WHOLE REASON IT EXISTS. A CSV
 * hands every value to Excel as text for Excel to guess at, and Excel guesses badly in ways that
 * silently destroy engineering data: a drawing number `03-0450` loses its leading zero, `1-2` becomes
 * the second of January, and a column of costs sorts as text so ₦900,000 files above ₦1,200,000. A
 * workbook states the type, so nothing is guessed.
 *
 * A CELL IS ONLY UPGRADED OUT OF TEXT WHEN TWO INDEPENDENT THINGS AGREE — the register's own schema
 * says the column is a number or a date, AND the printed value round-trips cleanly back to one. That
 * double test is what keeps `PRJ-0001`, `03-0450` and a phone number as text: they live in string
 * columns, so the first test fails and no amount of numeric-looking-ness will move them. Anything a
 * relation produced stays text too, because the cell holds a department's NAME and the column behind
 * it holds an id, and typing it by that column's schema would be typing the wrong thing.
 *
 * EVERY REMAINING CELL IS WRITTEN AS AN EXPLICIT STRING, which is also the formula-injection defence
 * (the one the CSV spends a paragraph on). PhpSpreadsheet decides a value beginning `=` is a formula
 * unless it is told the data type; told it, `=HYPERLINK("http://…")` in a document title is a cell
 * containing that text rather than code that runs on the machine of somebody who never visited this
 * platform. Stating the type is a better defence than the CSV's leading apostrophe because it does
 * not alter the value — the apostrophe is visible to anybody who clicks the cell.
 *
 * AND IT IS BUILT IN MEMORY, which nothing else in the export path is. The CSV streams and the PDF
 * is one string; a workbook is an object graph that exists in full before a byte is written, and a
 * twenty-thousand-row one will exhaust a shared host (§95). That single fact is why the queue, the
 * row threshold and the signed link landed in the same part as this class rather than after it.
 */
class ExcelRenderer
{
    /**
     * Excel's own ceiling, and not a policy of ours.
     *
     * A worksheet holds 1,048,576 rows. The report ceiling is far below it, so this is here only so
     * that a future caller raising MAX_ROWS discovers the real limit from a named constant rather
     * than from a corrupt file.
     */
    public const SHEET_ROW_LIMIT = 1_048_576;

    /** What a date column looks like when the file is opened, in the order this platform writes. */
    private const DATE_FORMAT = 'dd mmm yyyy';

    private const DATETIME_FORMAT = 'dd mmm yyyy hh:mm';

    /**
     * The whole workbook, as bytes.
     *
     * Returned rather than written, because who stores it and where is the caller's decision: the
     * synchronous path streams it straight back and never touches the disk, and the queued path puts
     * it on the private disk behind a policy (§67). A renderer that chose for both would have made
     * the small-export case store a file it did not need.
     */
    public function render(ReportDefinition $report, ReportResult $result, User $actor): string
    {
        $book = new Spreadsheet;

        $book->getProperties()
            ->setCreator($actor->name)
            ->setTitle($report->name)
            ->setDescription((string) $report->description)
            // The provenance line again, in the file's own metadata as well as on the sheet. A
            // workbook gets its top rows deleted by whoever tidies it up before forwarding it; the
            // properties survive that, and are where somebody looks when the sheet no longer says.
            ->setCompany('Shows only what '.$actor->name.' was permitted to see.');

        $sheet = $book->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($report));

        $grouped = $result->isGrouped();
        $headings = array_values($result->columns);
        $fields = array_keys($result->columns);

        if ($grouped) {
            array_unshift($headings, 'Group');
        }

        $row = $this->preamble($sheet, $report, $result, $actor, count($headings));

        $headerRow = $row;

        foreach ($headings as $index => $heading) {
            $sheet->setCellValueExplicit(
                [$index + 1, $headerRow],
                $heading,
                DataType::TYPE_STRING,
            );
        }

        $sheet->getStyle($this->range(1, $headerRow, count($headings), $headerRow))->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2F7']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $types = $this->cellTypes($report, $fields);
        $row = $headerRow + 1;

        foreach ($result->groups as $group) {
            foreach ($group['rows'] as $record) {
                $column = 1;

                if ($grouped) {
                    // The group on every row rather than as a heading, for the reason the CSV gives:
                    // a workbook is sorted and filtered by whoever opens it, and a heading row
                    // becomes a stray line in the middle of the data the first time somebody sorts.
                    $sheet->setCellValueExplicit([$column++, $row], (string) ($group['label'] ?? ''), DataType::TYPE_STRING);
                }

                foreach ($fields as $field) {
                    $this->write($sheet, $column++, $row, (string) ($record[$field] ?? ''), $types[$field]);
                }

                $row++;
            }
        }

        $lastRow = $row - 1;

        // Freeze below the headings and above nothing else. A report is read by scrolling, and a
        // spreadsheet whose headings scroll away is one where somebody reads a date as a cost.
        $sheet->freezePane([1, $headerRow + 1]);

        if ($lastRow >= $headerRow) {
            $sheet->setAutoFilter($this->range(1, $headerRow, count($headings), $lastRow));
        }

        foreach (range(1, max(1, count($headings))) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return $this->bytes($book);
    }

    // ================================================================== the top of the sheet

    /**
     * Who ran it, when, and whether the list is complete — above the data, never below it.
     *
     * The same four lines the CSV writes and the PDF prints, in the same words. A spreadsheet is
     * totalled from the bottom, so a truncation warning after the last row is a warning underneath
     * the sum nobody scrolled past.
     *
     * @return int the row the headings go on
     */
    private function preamble(
        Worksheet $sheet,
        ReportDefinition $report,
        ReportResult $result,
        User $actor,
        int $width,
    ): int {
        $lines = [$report->name];

        if ($report->description) {
            $lines[] = (string) $report->description;
        }

        $lines[] = 'Run '.($result->ranAt?->format('j M Y, H:i') ?? '');

        // WHOSE VIEW THIS IS. Two colleagues comparing two exports of one report otherwise conclude
        // the platform miscounts rather than that they have different reach (§61).
        $lines[] = 'Shows only what '.$actor->name.' is permitted to see.';

        if ($result->truncationNotice() !== null) {
            $lines[] = $result->truncationNotice();
        }

        $row = 1;

        foreach ($lines as $index => $line) {
            $sheet->setCellValueExplicit([1, $row], $line, DataType::TYPE_STRING);

            if ($index === 0) {
                $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(13);
            }

            if ($line === $result->truncationNotice()) {
                $sheet->getStyle([1, $row])->getFont()->setBold(true)->getColor()->setRGB('B42318');
            }

            if ($width > 1) {
                $sheet->mergeCells($this->range(1, $row, $width, $row));
            }

            $row++;
        }

        return $row + 1;
    }

    // ================================================================== typing

    /**
     * What each column may become, decided once for the whole sheet rather than per cell.
     *
     * Per cell would be both slower and wrong: a column where nine values parse as numbers and the
     * tenth is "Not set" must be one type, and deciding row by row produces a column Excel cannot
     * sort. So the schema decides what the column MAY be, and each cell that does not round-trip
     * falls back to text on its own.
     *
     * @param  array<int,string>  $fields
     * @return array<string,string> field => 'number' | 'date' | 'datetime' | 'text'
     */
    private function cellTypes(ReportDefinition $report, array $fields): array
    {
        $schema = KpiCatalogue::columnTypesFor($report->entity_type);
        $types = [];

        foreach ($fields as $field) {
            // A relation cell holds a name; the column behind it holds an id. Typing it by that
            // column's schema would write the department's NAME into a cell declared numeric.
            if (ReportCatalogue::relationFor($report->entity_type, $field) !== null) {
                $types[$field] = 'text';

                continue;
            }

            $types[$field] = match (true) {
                $this->isNumeric($schema[$field] ?? '') => 'number',
                $this->isDateTime($schema[$field] ?? '') => 'datetime',
                $this->isDate($schema[$field] ?? '') => 'date',
                default => 'text',
            };
        }

        return $types;
    }

    private function isNumeric(string $type): bool
    {
        return in_array($type, [
            'int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint',
            'decimal', 'numeric', 'float', 'double', 'real',
        ], true);
    }

    private function isDateTime(string $type): bool
    {
        return in_array($type, ['datetime', 'timestamp'], true);
    }

    private function isDate(string $type): bool
    {
        return $type === 'date';
    }

    /**
     * One cell, upgraded out of text only where the printed value agrees with the schema.
     *
     * The fall-through is the important branch. Every path that is not a clean round-trip ends in
     * setCellValueExplicit(…, TYPE_STRING), which is both the honest rendering and the injection
     * defence — there is no route through this method that lets a value be interpreted.
     */
    private function write(
        Worksheet $sheet,
        int $column,
        int $row,
        string $value,
        string $type,
    ): void {
        if ($value === '') {
            return;
        }

        if ($type === 'number' && is_numeric($value)) {
            $sheet->setCellValueExplicit([$column, $row], $value + 0, DataType::TYPE_NUMERIC);

            return;
        }

        if ($type === 'date' || $type === 'datetime') {
            // The two formats RecordPresenter prints, and only those. Parsed strictly rather than
            // with Carbon's forgiving constructor, because a forgiving parse turns a job number
            // that happens to read like a date into a date — silently, and only in the export.
            $moment = $this->parse($value);

            if ($moment !== null) {
                $sheet->setCellValueExplicit(
                    [$column, $row],
                    ExcelDate::PHPToExcel($moment),
                    DataType::TYPE_NUMERIC,
                );

                $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode(
                    $type === 'datetime' && $moment->format('H:i:s') !== '00:00:00'
                        ? self::DATETIME_FORMAT
                        : self::DATE_FORMAT,
                );

                return;
            }
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
    }

    /**
     * The printed value back as a moment, or null if it is not exactly one of the two printed forms.
     *
     * CARBON THROWS WHERE PHP RETURNS FALSE. `createFromFormat` on a string the format cannot
     * consume raises InvalidFormatException rather than returning false — so the first of the two
     * formats tried here ('j M Y H:i' against a plain date) is an exception in the normal case, not
     * an error. Catching it is the loop working; letting it escape would mean the first dated cell
     * in a report brings the whole export down with "Not enough data available to satisfy format".
     */
    private function parse(string $value): ?CarbonImmutable
    {
        foreach (['j M Y H:i', 'j M Y'] as $format) {
            try {
                $moment = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }

            // The round-trip is the strictness. A parse that succeeded but does not print back
            // identically means the format guessed at something the string did not say.
            if ($moment !== false && $moment->format($format) === $value) {
                return $moment;
            }
        }

        return null;
    }

    // ================================================================== plumbing

    private function range(int $fromColumn, int $fromRow, int $toColumn, int $toRow): string
    {
        return Coordinate::stringFromColumnIndex($fromColumn).$fromRow
            .':'.Coordinate::stringFromColumnIndex($toColumn).$toRow;
    }

    /**
     * A sheet name Excel will actually accept.
     *
     * Excel refuses `: \ / ? * [ ]` and anything over 31 characters, and refuses the whole FILE
     * rather than the name — so a company that names a report "Q1: Piping / Civil" would otherwise
     * get a workbook that will not open, with nothing to indicate why.
     */
    private function sheetTitle(ReportDefinition $report): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $report->name);
        $title = trim(preg_replace('/\s+/', ' ', (string) $title));

        return mb_substr($title === '' ? 'Report' : $title, 0, 31);
    }

    /**
     * The writer's output, captured.
     *
     * PhpSpreadsheet writes to a path or to php://output; there is no "give me a string". Writing
     * to a temporary file would put an unprotected copy of one person's rows on the disk for as long
     * as the write takes, which is the exact thing §67 is about — so the output buffer it is, and
     * the workbook is released immediately afterwards because it is the largest thing in memory.
     */
    private function bytes(Spreadsheet $book): string
    {
        $writer = new Xlsx($book);

        // Pre-calculation would evaluate formulas that do not exist here — every cell is a literal
        // — and costs a pass over the whole sheet to discover that.
        $writer->setPreCalculateFormulas(false);

        ob_start();

        try {
            $writer->save('php://output');

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
    }
}
