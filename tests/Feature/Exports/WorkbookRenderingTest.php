<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\ReportDefinitionService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * What a spreadsheet does to a report that a screen does not (Phase 8, Part 5).
 *
 * THE REASON EXCEL EXISTS HERE IS TYPES, NOT POLISH. A CSV carries no types, so the receiving
 * program guesses — and Excel's guesses are destructive in a way that is silent and permanent.
 * `03-0450` is a document number until Excel decides it is the third of April; `1-2` is a revision
 * until it is the first of February; a cost column that arrives as text sorts 100 before 99 and the
 * total at the bottom of somebody's budget review is wrong. Nobody notices, because the file opened
 * fine.
 *
 * SO A CELL IS ONLY UPGRADED OUT OF TEXT WHEN TWO INDEPENDENT THINGS AGREE: the register's own
 * schema says that column is a number or a date, AND the printed value round-trips as one. Either
 * alone would be a guess. A relation column is always text, because the cell holds a name while the
 * column behind it holds an id.
 *
 * AND EVERYTHING ELSE IS WRITTEN AS AN EXPLICIT STRING, which is also the formula-injection
 * defence: a spreadsheet is a program, company-entered text beginning `=` is a line of it, and the
 * person who opens the file is usually outside this platform's control entirely (§84).
 */
final class WorkbookRenderingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private User $tunde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'report.view' => 'organization',
            'report.create' => 'organization',
            'report.export' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== types

    #[Test]
    public function a_number_the_register_calls_a_number_is_written_as_one(): void
    {
        $document = $this->documentCalled('Lifting operations procedure');

        $sheet = $this->sheet();

        // The id column is an integer in the register's own schema and prints as digits, so both
        // halves agree and the cell can be summed, sorted and counted.
        $cell = $this->find($sheet, (string) $document->getKey());

        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
    }

    #[Test]
    public function a_reference_that_merely_looks_like_a_date_stays_text(): void
    {
        $document = $this->documentCalled('Lifting operations procedure');

        $sheet = $this->sheet();

        // The document number is the case the whole renderer was written for. Whatever this
        // company's numbering produces — `PRC-0001`, `03-0450`, `2026/14` — the register calls the
        // column a varchar, so it is written as text and survives being opened.
        $cell = $this->find($sheet, (string) $document->number);

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame($document->number, $cell->getValue());
    }

    #[Test]
    public function a_relation_is_text_even_though_the_column_behind_it_is_a_number(): void
    {
        $this->documentCalled('Lifting operations procedure');

        $sheet = $this->sheet();

        // `org_unit_id` is an integer column and the cell holds "Civil". Typing this one off the
        // schema alone would ask Excel to read a department name as a number — which it would do,
        // by making it an error or a zero.
        $cell = $this->find($sheet, 'Civil');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }

    #[Test]
    public function a_date_the_register_calls_a_date_is_written_as_one(): void
    {
        $this->documentCalled('Lifting operations procedure', [
            'next_review_on' => '2027-03-14',
        ]);

        $sheet = $this->sheet();

        $cell = $this->findByFormat($sheet, 'dd mmm yyyy');

        // Written as a real date rather than the printed string, so the column sorts chronologically
        // instead of alphabetically — which is the difference between a review schedule and a list.
        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
        $this->assertSame('14 Mar 2027', $cell->getFormattedValue());
    }

    // ================================================================== §84

    #[Test]
    public function a_title_that_begins_with_an_equals_sign_is_not_a_formula(): void
    {
        $this->documentCalled('=1+1 revised acceptance criteria');

        $sheet = $this->sheet();

        $cell = $this->find($sheet, '=1+1 revised acceptance criteria');

        // Written explicitly as a string, so PhpSpreadsheet stores it as text rather than parsing
        // it. The real payloads are worse than arithmetic — `=cmd|…` and `=HYPERLINK(…)` are the
        // ones that matter — but they all rely on the same first character being taken seriously.
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=1+1 revised acceptance criteria', $cell->getValue());
    }

    // ================================================================== the shape of the sheet

    #[Test]
    public function the_workbook_says_who_produced_it_and_when(): void
    {
        $this->documentCalled('Lifting operations procedure');

        $book = $this->book();

        // §59 in the file itself. An export that has been forwarded twice and renamed once is still
        // answerable about where it came from — and unlike the PDF's footer, this cannot be edited
        // out by deleting a paragraph.
        $properties = $book->getProperties();

        $this->assertStringContainsString('Tunde Bello', $properties->getCreator().' '.$properties->getCompany().' '.$properties->getDescription());
        $this->assertStringContainsString('Procedures on file', $properties->getTitle());
    }

    #[Test]
    public function the_header_row_stays_put_and_the_columns_can_be_filtered(): void
    {
        $this->documentCalled('Lifting operations procedure');

        $sheet = $this->sheet();

        // Both are the difference between a workbook somebody reads and one they export again into
        // something else. A five-thousand-row sheet with a scrolling header is unusable at row 200.
        $this->assertNotSame('', $sheet->getFreezePane() ?? '');
        $this->assertNotNull($sheet->getAutoFilter()->getRange());
    }

    #[Test]
    public function the_sheet_name_survives_a_report_title_excel_would_refuse(): void
    {
        $this->documentCalled('Lifting operations procedure');

        // Excel forbids : \ / ? * [ ] in a sheet name and stops at 31 characters. A company is
        // entitled to call a report whatever it likes; the file has to open regardless.
        $report = $this->report([
            'name' => 'Procedures: drawings/models [2026] — the long standing register',
        ]);

        $sheet = $this->sheetFor($report);

        $this->assertLessThanOrEqual(31, mb_strlen($sheet->getTitle()));
        $this->assertSame(0, preg_match('/[:\\\\\\/?*\[\]]/', $sheet->getTitle()));
    }

    // ================================================================== word

    #[Test]
    public function the_word_document_carries_the_rows_and_its_own_provenance(): void
    {
        $this->documentCalled('Lifting operations procedure');

        $report = $this->report();

        $bytes = $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'docx']))
            ->assertOk()
            ->getContent();

        $xml = $this->unzip($bytes);

        $this->assertStringContainsString('Lifting operations procedure', $xml['word/document.xml']);

        // The provenance lives in the page FOOTER rather than in the body, because this is the copy
        // somebody is meant to edit and a paragraph at the top is the first thing they will delete.
        $footer = implode('', array_filter(
            $xml,
            fn (string $name) => str_starts_with($name, 'word/footer'),
            ARRAY_FILTER_USE_KEY,
        ));

        $this->assertStringContainsString('Tunde Bello', $footer);
    }

    // ================================================================== helpers

    private function book(?ReportDefinition $report = null): Spreadsheet
    {
        $report ??= $this->report();

        $bytes = $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'xlsx']))
            ->assertOk()
            ->getContent();

        // Round-tripped through a real file and a real reader rather than inspected in memory. What
        // is being tested is what Excel will do with this, so the assertions have to run against
        // what actually got written rather than against the objects that wrote it.
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            file_put_contents($path, $bytes);

            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    private function sheet(): Worksheet
    {
        return $this->book()->getActiveSheet();
    }

    private function sheetFor(ReportDefinition $report): Worksheet
    {
        return $this->book($report)->getActiveSheet();
    }

    /** The one cell holding exactly this value. */
    private function find(Worksheet $sheet, string $value): Cell
    {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ((string) $cell->getValue() === $value) {
                    return $cell;
                }
            }
        }

        $this->fail('No cell in the workbook holds “'.$value.'”.');
    }

    private function findByFormat(Worksheet $sheet, string $format): Cell
    {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getStyle()->getNumberFormat()->getFormatCode() === $format) {
                    return $cell;
                }
            }
        }

        $this->fail('No cell in the workbook is formatted as “'.$format.'”.');
    }

    /** @return array<string,string> */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');

        try {
            file_put_contents($path, $bytes);

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true, 'The Word document is not a readable archive.');

            $parts = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $parts[$name] = (string) $zip->getFromIndex($i);
            }

            $zip->close();

            return $parts;
        } finally {
            @unlink($path);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    private function report(array $attributes = []): ReportDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'columns' => ['id', 'number', 'title', 'org_unit_id', 'next_review_on'],
            ...$attributes,
        ], $this->tunde));
    }

    /** @param  array<string,mixed>  $attributes */
    private function documentCalled(string $title, array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
            ...$attributes,
        ]);
    }
}
