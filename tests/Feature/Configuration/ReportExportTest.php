<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\ActivityLog;
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
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Carrying a report out of the building (Phase 7, Part 6D).
 *
 * AN EXPORT IS THE ONE PLACE WHERE THE PLATFORM'S AUTHORIZATION STOPS APPLYING. Everything else it
 * shows can be withdrawn: revoke a permission and the next page load is narrower. A file cannot be
 * withdrawn. It is on a laptop, in an inbox, in a shared drive, and it will be opened years later by
 * somebody who never had an account here. Every assertion in this file follows from that.
 *
 *   THE FILE CONTAINS WHAT THE EXPORTER MAY SEE AND NOTHING ELSE (§61), and it is produced by
 *   running the report again at the moment of the click rather than reusing what was on screen.
 *
 *   EXPORTING IS AUDITED, WITH THE CONDITIONS AND THE ROW COUNT (§59). The question that gets asked
 *   after a leak is "who took this, when, and what was in it", and only the audit entry can answer
 *   the third part — the file itself is the thing that has gone missing.
 *
 *   NOTHING IS WRITTEN TO DISK (§67). The strongest compliance with a rule about stored files is to
 *   have no stored file: nothing to path-guess, nothing to sweep up, no window between write and
 *   delete, and no second route that has to re-derive who may download it.
 *
 *   A SPREADSHEET IS A PROGRAM. Company-entered text that begins `=` is a formula to Excel, and the
 *   person who opens it is usually outside the platform's control entirely.
 */
final class ReportExportTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $ngozi;

    private User $tunde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $engineering);

        // Ngozi may export, and sees only Civil. That combination is the point of her: an exporter
        // whose file must still be narrower than the company.
        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'report.view' => 'organization',
            'report.export' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'report.view' => 'organization',
            'report.create' => 'organization',
            'report.edit' => 'organization',
            'report.export' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== §61, in a file

    #[Test]
    public function the_file_carries_only_the_rows_the_person_exporting_it_may_see(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $csv = $this->csvFor($this->ngozi, $this->report());

        $this->assertStringContainsString('Lifting operations procedure', $csv);

        // The one that matters. A department controller's export of a company report is a
        // department's worth of rows — otherwise the export route is a way around every
        // permission the platform grants, and the easiest one to find.
        $this->assertStringNotContainsString('Hydrotest procedure', $csv);
    }

    #[Test]
    public function the_export_is_run_again_rather_than_taken_from_the_screen(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        // She loads the page and sees her row.
        $this->actingFresh($this->ngozi)
            ->get(route('configuration.reports.show', $report))
            ->assertOk()
            ->assertSee('Lifting operations procedure');

        // Her access is withdrawn while the page sits open — a role changed, a transfer, a
        // suspension. Then she presses Export.
        // Withdrawn the way the application withdraws it: the assignments go, the tenant's
        // permission version moves — that version is what the resolver's cache key is built from —
        // and the actor is read back from the database. The last step is not ceremony. The cache
        // key is derived from `$user->organization->permissions_version`, and a User instance that
        // has already loaded its organization keeps serving the version it loaded, so a test that
        // reused the old instance would be asserting against a cache rather than an access
        // decision, and would pass whatever the roles table said.
        $this->withinOrganization($this->org, function () {
            $this->ngozi->roles()->sync([]);
            $this->org->bumpPermissionsVersion();
        });

        $this->ngozi = $this->withinOrganization($this->org,
            fn () => $this->ngozi->fresh(['roles', 'employee']));

        $this->actingFresh($this->ngozi)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'csv']))
            ->assertForbidden();
    }

    #[Test]
    public function withdrawn_register_access_narrows_the_file_even_when_the_export_permission_remains(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        // She keeps `report.export` and loses `document.view`. The subtler half of the previous
        // test: the route still lets her through, and the file must come back empty rather than
        // full, because the rows are re-derived rather than remembered.
        $this->withinOrganization($this->org, function () {
            $bare = $this->makeRole($this->org, 'Exporter only', [
                'report.view' => 'organization',
                'report.export' => 'organization',
            ]);

            $this->ngozi->roles()->sync([$bare->getKey()]);
            $this->org->bumpPermissionsVersion();
        });

        $this->ngozi = $this->withinOrganization($this->org,
            fn () => $this->ngozi->fresh(['roles', 'employee']));

        $csv = $this->csvFor($this->ngozi, $report);

        $this->assertStringNotContainsString('Lifting operations procedure', $csv);
    }

    // ================================================================== §59, the record of it

    #[Test]
    public function exporting_is_recorded_with_its_conditions_and_its_row_count(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $report = $this->report();

        $this->actingFresh($this->tunde)->get(route('configuration.reports.export', [
            'report' => $report,
            'format' => 'csv',
            'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->piping->getKey()]],
        ]))->assertOk();

        $entry = $this->exportEntryFor($report);

        $this->assertNotNull($entry);
        $this->assertSame('csv', $entry->properties['format']);
        $this->assertSame(1, $entry->properties['rows']);
        $this->assertSame($this->tunde->getKey(), $entry->causer_id);

        // The two filter sets are kept apart on purpose. Two years later, "the report was built to
        // show everything and this person narrowed it to Piping on the day" and "the report has
        // always been a Piping report" are different facts, and only one of them is about the
        // person who exported it.
        $this->assertSame([], $entry->properties['filters']);
        $this->assertSame('org_unit_id', $entry->properties['ad_hoc_filters'][0]['field']);
    }

    #[Test]
    public function the_record_is_written_before_the_file_streams(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        $response = $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'csv']))
            ->assertOk();

        // The audit entry exists before anybody reads the body. A streamed response runs its
        // callback after the framework has handed off, so an export audited inside the stream
        // would leave no trace at all when somebody cancels the download half way — which is
        // exactly the export worth having a trace of.
        $this->assertNotNull($this->exportEntryFor($report));

        $response->streamedContent();
    }

    #[Test]
    public function two_exports_of_one_report_are_two_entries(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        $this->csvFor($this->tunde, $report);
        $this->csvFor($this->ngozi, $report);

        // Not "this report has been exported". Who, and how many times, is the question (§59).
        $this->assertSame(2, ActivityLog::query()->withoutGlobalScopes()
            ->forRecord($report)->where('verb', 'exported')->count());
    }

    // ================================================================== §67, no artefact

    #[Test]
    public function an_export_leaves_nothing_behind_on_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->csvFor($this->tunde, $this->report());

        // Nothing to path-guess, nothing to sweep up, no window between writing and deleting, and
        // no second route that would have to work out all over again who may download it.
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ================================================================== the file itself

    #[Test]
    public function a_title_that_looks_like_a_formula_is_not_one_when_the_spreadsheet_opens_it(): void
    {
        $this->documentIn($this->civil, '=cmd|\' /c calc\'!A0');
        $this->documentIn($this->civil, '+441234567890 site office');

        $csv = $this->csvFor($this->tunde, $this->report());

        // Neither cell may start with the character that makes Excel treat it as code. A document
        // title is company-entered text, and the person who opens this file is frequently a client
        // who has never authenticated to this platform and never agreed to run anything.
        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringContainsString("'+441234567890", $csv);
    }

    #[Test]
    public function the_filename_cannot_carry_a_path_or_a_header(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report(['name' => '../../etc/"passwd"'."\r\n".'X-Injected: yes']);

        $disposition = $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'csv']))
            ->assertOk()
            ->headers->get('Content-Disposition');

        $disposition = (string) $disposition;

        // A report name is company-entered text that ends up inside a `Content-Disposition` header
        // and then inside a filename on somebody's machine. Both of those interpret characters, and
        // the ones that matter are the structural ones: a CR or LF ends the header and begins
        // another, a quote closes the filename early, a slash or `..` is a path when it is saved.
        //
        // Note what is NOT asserted: that the words of the name survive. "X-Injected" is still in
        // there as letters, and as letters it is a filename nobody chose rather than a header.
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString(':', $disposition);
        $this->assertSame(2, substr_count($disposition, '"'));
    }

    #[Test]
    public function a_file_that_stops_short_says_so_at_the_top(): void
    {
        foreach (range(1, 4) as $n) {
            $this->documentIn($this->civil, 'Procedure '.$n);
        }

        $csv = $this->csvFor($this->tunde, $this->report(['row_limit' => 2]));

        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $head = implode("\n", array_slice($lines, 0, 6));

        // At the top, above the data, where somebody who never scrolls to the bottom of five
        // thousand rows still reads it. A truncated export that looks complete is how a partial
        // list becomes a decision — and a spreadsheet is totalled from the bottom, so a warning
        // placed after the last row sits underneath the sum nobody scrolled past.
        $this->assertStringContainsString('stopped at 2 rows', $head);
        $this->assertStringNotContainsString('Procedure 1', $csv);
    }

    #[Test]
    public function the_file_says_whose_view_it_is(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $csv = $this->csvFor($this->ngozi, $this->report());

        // The single most useful line in the file. Two colleagues comparing two exports of one
        // report find the explanation in the file rather than concluding the platform miscounts.
        $this->assertStringContainsString('Ngozi Eze', $csv);
    }

    #[Test]
    public function the_pdf_comes_back_as_a_pdf_and_is_not_cacheable(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $response = $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.export', ['report' => $this->report(), 'format' => 'pdf']))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // No-store, because the rows in it belong to one reader and a shared proxy handing the
        // director's copy to the next person asking is the whole of §61 undone by a header.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_format_nobody_offers_is_a_404_rather_than_a_guess(): void
    {
        $report = $this->report();

        // `xlsx` used to be on this list and is now a real format (Phase 8, part 5). The list is
        // deliberately not a list of dangerous strings — it is a list of plausible ones, because the
        // check that matters is that the format is looked up in FORMATS rather than believed.
        foreach (['ods', 'html', 'php', '../../config/app'] as $format) {
            $this->actingFresh($this->tunde)
                ->get(route('configuration.reports.export', ['report' => $report, 'format' => $format]))
                ->assertNotFound();
        }
    }

    // ================================================================== fixture

    private function exportEntryFor(ReportDefinition $report): ?ActivityLog
    {
        return ActivityLog::query()->withoutGlobalScopes()
            ->forRecord($report)
            ->where('verb', 'exported')
            ->latest('id')
            ->first();
    }

    private function csvFor(User $actor, ReportDefinition $report): string
    {
        return $this->actingFresh($actor)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();
    }

    /** @param  array<string,mixed>  $attributes */
    private function report(array $attributes = []): ReportDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'columns' => ['title', 'org_unit_id'],
            ...$attributes,
        ], $this->tunde));
    }

    /** @param  array<string,mixed>  $attributes */
    private function documentIn(OrgUnit $unit, string $title, array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $unit->getKey(),
            ...$attributes,
        ]);
    }
}
