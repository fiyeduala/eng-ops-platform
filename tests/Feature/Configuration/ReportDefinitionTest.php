<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Report\ReportResult;
use App\Engines\Report\ReportRunner;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\ReportDefinitionService;
use App\Support\Enums\OrgUnitType;
use App\Support\ReportCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The report engine and the builder over it (Phase 7, Part 6B/6E).
 *
 * A REPORT IS THE MOST DANGEROUS READ SURFACE IN THE PLATFORM, and the reason is not that it is
 * powerful but that it is quiet. An index page shows one register with the filters somebody can see;
 * a report names its own register, its own columns and its own conditions, prints them with
 * authority, and can be exported into a file that leaves the building. Every failure mode is silent:
 * a report that lists rows the reader may not see looks exactly like a report that does not.
 *
 * So the assertions here are almost all about absence — rows that must not appear, fields that must
 * not be offerable, companies that must not exist:
 *
 *   THE ROWS BELONG TO WHOEVER RAN IT (§61). Two people open one report and correctly get different
 *   row counts, because a report is `visibleTo($viewer)` and nothing else.
 *
 *   THE COLUMN LIST AND THE CONDITION LIST ARE DIFFERENT LISTS. A free-text title is printable and
 *   is NOT filterable, because printing forty titles somebody may see is not the same as letting
 *   them ask forty thousand yes/no questions about titles they may not (§61, §84).
 *
 *   EVERY FIELD IS WHITELISTED ON SAVE AND AGAIN ON RUN (§84). A definition outlives the register
 *   it names, so "it was valid when it was saved" is not a property the runner may rely on.
 *
 *   ANOTHER COMPANY'S REPORT DOES NOT EXIST (§75, §94).
 *
 * The fixture is KpiScreenTest's, deliberately, so the same three readers and the same three
 * documents can be read across both files.
 *
 *   Civil (dept)    Ngozi   document controller, Civil only — may read reports, not build them
 *   Piping (dept)   Sade    engineer, Piping only
 *   (company)       Tunde   operations director, the whole company, may build and export
 */
final class ReportDefinitionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $ngozi;

    private User $sade;

    private User $tunde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'report.view' => 'organization',
        ]);

        $engineer = $this->makeRole($this->org, 'Engineer', [
            'document.view' => 'department',
            'report.view' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'report.view' => 'organization',
            'report.create' => 'organization',
            'report.edit' => 'organization',
            'report.delete' => 'organization',
            'report.export' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$engineer]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== §61, the whole point

    #[Test]
    public function one_report_answers_differently_for_each_person_who_runs_it(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Excavation procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $report = $this->report();

        // Three documents exist and there is one report. The director gets three rows, the Civil
        // controller two, the Piping engineer one — and every one of those is the correct answer
        // to "list the procedures on file", because the rest are not on file as far as that reader
        // is concerned. Nothing about the definition changed between the three runs.
        $this->assertSame(3, $this->runFor($report, $this->tunde)->total);
        $this->assertSame(2, $this->runFor($report, $this->ngozi)->total);
        $this->assertSame(1, $this->runFor($report, $this->sade)->total);
    }

    #[Test]
    public function the_rows_a_reader_gets_are_the_rows_that_reader_may_see(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $report = $this->report();

        $rows = $this->runFor($report, $this->sade)->rows();
        $titles = array_column($rows, 'title');

        // The count matching is not enough. A report that returned the right NUMBER of rows and the
        // wrong ones would pass the test above and disclose a document.
        $this->assertSame(['Hydrotest procedure'], $titles);
    }

    #[Test]
    public function opening_a_report_is_not_seeing_its_rows(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        $storeman = $this->makePerson($this->org, 'Chidi Okafor', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['report.view' => 'organization']),
        ]);

        // Holds `report.view` and no document permission at all. The report opens — the question,
        // the columns, the description — and the table is empty. Hiding the report from him instead
        // would imply the register permission is what puts rows in it, which is exactly the
        // confusion the run screen's banner exists to prevent.
        $this->actingFresh($storeman)
            ->get(route('configuration.reports.show', $report))
            ->assertOk()
            ->assertSee('Procedures on file')
            ->assertSee('Nothing matched');

        $this->assertSame(0, $this->runFor($report, $storeman)->total);
    }

    #[Test]
    public function the_run_screen_says_out_loud_that_the_rows_are_the_readers_own(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        // Without this sentence, the first test in this file is a bug report. A director whose
        // export has three rows where his controller's has two will either not notice and send the
        // wrong one to a client, or conclude the platform cannot be trusted.
        $this->actingFresh($this->ngozi)
            ->get(route('configuration.reports.show', $this->report()))
            ->assertOk()
            ->assertSee('are allowed to see', false);
    }

    // ================================================================== §75 / §94

    #[Test]
    public function another_companys_report_does_not_exist(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirs = $this->withinOrganization($other, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Their procedures',
            'entity_type' => 'document',
            'columns' => ['title'],
        ], $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', ['report.view' => 'organization', 'report.create' => 'organization']),
        ])));

        // The URL is guessable, the id is sequential, and the director holds every report
        // permission there is. None of that is an argument (§84).
        $this->actingFresh($this->tunde)->get(route('configuration.reports.show', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->get(route('configuration.reports.edit', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->put(route('configuration.reports.update', $theirs), $this->payload())->assertNotFound();
        $this->actingFresh($this->tunde)->delete(route('configuration.reports.destroy', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->get(route('configuration.reports.export', ['report' => $theirs, 'format' => 'csv']))->assertNotFound();
    }

    #[Test]
    public function the_runner_refuses_a_report_belonging_to_another_company_even_when_called_directly(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirs = $this->withinOrganization($other, function () use ($other) {
            $report = new ReportDefinition;
            $report->forceFill([
                'organization_id' => $other->getKey(),
                'name' => 'Their procedures',
                'entity_type' => 'document',
                'columns' => ['title'],
                'filters' => [],
                'is_active' => true,
            ])->save();

            return $report;
        });

        // The controller would have 404'd first. This asserts the engine refuses on its own
        // account, so the next caller — a scheduled run, a console command, a dashboard tile —
        // inherits the guard rather than having to remember it (§75).
        $result = app(ReportRunner::class)->run($theirs, $this->tunde);

        $this->assertFalse($result->available);
        $this->assertSame(0, $result->total);
    }

    // ================================================================== §84, the field lists

    #[Test]
    public function a_column_the_register_does_not_offer_is_refused_on_save(): void
    {
        $this->actingFresh($this->tunde)
            ->post(route('configuration.reports.store'), $this->payload([
                'columns' => ['title', 'organization_id'],
            ]))
            ->assertSessionHasErrors();

        // REFUSED, NOT QUIETLY TRIMMED. Saving the good column and dropping the bad one would leave
        // somebody looking at a report that is missing a column they are certain they added, with
        // nothing anywhere saying why. `organization_id` is the tenant boundary and is never
        // printable; being told so is the only useful outcome.
        $this->assertSame(0, ReportDefinition::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function free_text_is_printable_and_is_not_filterable(): void
    {
        $catalogue = ReportCatalogue::class;

        // The asymmetry, asserted rather than merely documented. If somebody later "tidies up" the
        // catalogue by pointing both lists at the same source, this is the test that fails.
        $this->assertTrue($catalogue::offersColumn('document', 'title'));
        $this->assertFalse($catalogue::offersFilter('document', 'title'));

        // And the structured fields remain filterable, so the tidy-up cannot be done by emptying
        // the filter list either.
        $this->assertTrue($catalogue::offersFilter('document', 'org_unit_id'));
    }

    #[Test]
    public function a_condition_on_a_field_the_register_does_not_offer_is_refused(): void
    {
        $this->actingFresh($this->tunde)
            ->post(route('configuration.reports.store'), $this->payload([
                'filters' => [
                    ['field' => 'title', 'operator' => 'like', 'value' => '%confidential%'],
                ],
            ]))
            ->assertSessionHasErrors();

        // This is the §61 attack in its cheapest form: a report somebody re-saves twenty times,
        // reading the row count each time, to recover the titles of documents they may not open.
        // Note what is NOT acceptable here — saving the report with the filter silently discarded.
        // That would produce a report listing everything, which the attacker reads as a very
        // interesting answer, and which the honest builder mistakes for a working filter.
        $this->assertSame(0, ReportDefinition::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_definition_that_names_a_register_no_longer_offered_says_so_instead_of_listing_everything(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        // An upgrade withdraws the catalogue entry, or a module is switched off. The definition
        // survives it, because configuration outlives what it points at.
        $this->withinOrganization($this->org, fn () => $report
            ->forceFill(['entity_type' => 'nothing_like_this'])
            ->saveQuietly());

        $result = $this->runFor($report, $this->tunde);

        // An empty table and a broken report look identical, and they are opposite pieces of news.
        $this->assertFalse($result->available);
        $this->assertNotNull($result->reason);
    }

    #[Test]
    public function an_ad_hoc_condition_from_the_query_string_gets_the_same_whitelist_as_a_stored_one(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $report = $this->report();

        // Typed into the address bar rather than chosen from the bar on the page — which is the
        // whole reason the same compiler handles both. A field the register does not offer for
        // filtering is not one the URL can reintroduce.
        $response = $this->actingFresh($this->tunde)->get(route('configuration.reports.show', [
            'report' => $report,
            'filters' => [['field' => 'title', 'operator' => 'like', 'value' => 'Hydrotest%']],
        ]))->assertOk();

        $this->assertSame([], $response->viewData('adHoc'));
        $this->assertSame(2, $response->viewData('result')->total);
    }

    #[Test]
    public function an_ad_hoc_condition_on_an_offered_field_does_narrow_the_run(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $response = $this->actingFresh($this->tunde)->get(route('configuration.reports.show', [
            'report' => $this->report(),
            'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->piping->getKey()]],
        ]))->assertOk();

        // The other half of the previous test. A whitelist that refused everything would pass that
        // one and make the filter bar decorative.
        $this->assertSame(1, $response->viewData('result')->total);
    }

    // ================================================================== who may do what

    #[Test]
    public function reading_a_report_is_not_permission_to_rebuild_it(): void
    {
        $report = $this->report();

        $this->actingFresh($this->ngozi)->get(route('configuration.reports.index'))->assertOk();
        $this->actingFresh($this->ngozi)->get(route('configuration.reports.show', $report))->assertOk();

        // The list hides these buttons from her as a courtesy. The route is what stops her (§6).
        $this->actingFresh($this->ngozi)->get(route('configuration.reports.create'))->assertForbidden();
        $this->actingFresh($this->ngozi)->get(route('configuration.reports.edit', $report))->assertForbidden();
        $this->actingFresh($this->ngozi)->post(route('configuration.reports.store'), $this->payload())->assertForbidden();
        $this->actingFresh($this->ngozi)->put(route('configuration.reports.update', $report), $this->payload())->assertForbidden();
        $this->actingFresh($this->ngozi)->delete(route('configuration.reports.destroy', $report))->assertForbidden();
    }

    #[Test]
    public function reading_a_report_on_screen_is_not_permission_to_carry_it_out_of_the_building(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $report = $this->report();

        // Ngozi may see her two rows on screen all day. A file is different in kind: it outlives
        // the session, it leaves with the laptop, and nothing revokes it. That is why `export` is
        // its own ability rather than a consequence of `view`.
        $this->actingFresh($this->ngozi)
            ->get(route('configuration.reports.export', ['report' => $report, 'format' => 'csv']))
            ->assertForbidden();
    }

    #[Test]
    public function somebody_with_no_report_permission_cannot_open_the_section_at_all(): void
    {
        $storeman = $this->makePerson($this->org, 'Musa Dauda', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['document.view' => 'own']),
        ]);

        $this->actingFresh($storeman)->get(route('configuration.reports.index'))->assertForbidden();
        $this->actingFresh($storeman)->get(route('configuration.reports.create'))->assertForbidden();
        $this->actingFresh($storeman)->get(route('configuration.reports.show', $this->report()))->assertForbidden();
    }

    // ================================================================== §73, buildable by a person

    #[Test]
    public function a_report_can_be_built_end_to_end_through_the_screens(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        // §73 in one test: a non-developer opens the Configuration Center, finds the tile, fills in
        // the form and gets an answer. No seeder, no console command, no column names typed by hand.
        $this->actingFresh($this->tunde)
            ->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('Reports');

        $this->actingFresh($this->tunde)->get(route('configuration.reports.create'))->assertOk();

        $this->actingFresh($this->tunde)
            ->post(route('configuration.reports.store'), $this->payload([
                'name' => 'Procedures by department',
                'columns' => ['title', 'org_unit_id'],
                'group_field' => 'org_unit_id',
            ]))
            ->assertRedirect();

        $report = $this->withinOrganization($this->org, fn () => ReportDefinition::query()
            ->where('name', 'Procedures by department')->sole());

        $result = $this->runFor($report, $this->tunde);

        $this->assertTrue($result->available);
        $this->assertTrue($result->isGrouped());
        $this->assertSame(2, $result->total);

        // The grouping prints the department's NAME, not its id. A report that grouped by
        // `org_unit_id` and printed `7` would be technically correct and useless.
        $labels = array_column($result->groups, 'label');
        sort($labels);
        $this->assertSame(['Civil', 'Piping'], $labels);
    }

    #[Test]
    public function a_second_report_cannot_take_a_name_already_in_use_here(): void
    {
        $this->report();

        $this->actingFresh($this->tunde)
            ->post(route('configuration.reports.store'), $this->payload())
            ->assertSessionHasErrors('name');

        // The same name in another company is not a clash, because the two companies never see
        // each other's lists (§75).
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirs = $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', ['report.view' => 'organization', 'report.create' => 'organization']),
        ]);

        $this->withinOrganization($other, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'columns' => ['title'],
        ], $theirs));

        $this->assertSame(2, ReportDefinition::query()->withoutGlobalScopes()
            ->where('name', 'Procedures on file')->count());
    }

    #[Test]
    public function a_row_limit_above_the_ceiling_is_refused_rather_than_quietly_shortened(): void
    {
        $this->actingFresh($this->tunde)
            ->post(route('configuration.reports.store'), $this->payload([
                'row_limit' => (string) (ReportDefinition::MAX_ROWS + 1),
            ]))
            ->assertSessionHasErrors();

        // Clamping would have been friendlier and worse. Somebody who asked for ten thousand rows
        // and silently got five thousand reconciles that file against a system of record and finds
        // a discrepancy nobody can explain.
        $this->assertSame(0, ReportDefinition::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_report_that_stops_short_says_so(): void
    {
        foreach (range(1, 4) as $n) {
            $this->documentIn($this->civil, 'Procedure '.$n);
        }

        $report = $this->report(['row_limit' => 2]);

        $result = $this->runFor($report, $this->tunde);

        $this->assertTrue($result->truncated);
        $this->assertSame(2, $result->total);
        $this->assertNotNull($result->truncationNotice());

        $this->actingFresh($this->tunde)
            ->get(route('configuration.reports.show', $report))
            ->assertOk()
            ->assertSee($result->truncationNotice());
    }

    // ================================================================== fixture

    private function runFor(ReportDefinition $report, User $viewer): ReportResult
    {
        // Inside the tenant, because a bare `run()` with no organization context is not a scenario
        // the platform has — every real caller arrives through a request or a scheduled task that
        // established one. KpiDefinitionTest calls the evaluator the same way.
        return $this->withinOrganization($this->org,
            fn () => app(ReportRunner::class)->run($report, $viewer));
    }

    /**
     * A form submission, as the browser sends it — every value a string or an array of strings.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function payload(array $attributes = []): array
    {
        return [
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'columns' => ['title'],
            'sort_direction' => 'desc',
            'row_limit' => '500',
            'is_active' => '1',
            ...$attributes,
        ];
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
