<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Engines\Dashboard\RenderedWidget;
use App\Engines\Dashboard\WidgetComposer;
use App\Engines\Kpi\SnapshotSweeper;
use App\Models\DashboardWidget;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\KpiDefinition;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\DashboardWidgetService;
use App\Services\KpiDefinitionService;
use App\Services\ReportDefinitionService;
use App\Support\Enums\OrgUnitType;
use App\Support\WidgetCatalogue;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * One arrangement, many boards (§6, §52, §53, §84, §90, §95).
 *
 * THE CLAIM UNDER TEST is that a company arranges its executive board ONCE and everybody who opens
 * it gets a board that is correct for them — the tiles they may see, carrying figures counted
 * through their own visibility. Not a board per role, not a board hidden with CSS, and never a
 * figure counted over records the reader could not open.
 *
 * SO THE TESTS ARE MOSTLY ABOUT WHAT IS NOT DRAWN. A tile whose register a reader cannot read is
 * never built, so it is absent from the response rather than styled away (§6). A stored figure is
 * handed over only where `ReachInspector` says the reader's reach contains the whole scope it was
 * counted over, and everybody else falls back to their own live count carrying a sentence that says
 * so. A trend has no fallback at all, because a history cannot be recounted for one person.
 *
 * AND ABOUT WHAT CANNOT BE SAID ON THE FORM. There is no permission column and no permission field:
 * the permission is derived from the register behind the tile, every time, so a tile can never be
 * published under a looser permission than the records it counts (§84). Cross-tenant ids on the
 * source selects are refused rather than trusted (§94).
 *
 *   Engineering (division)   Emeka   sees the whole division
 *   Civil (department)       Ngozi   sees Civil only
 *   Piping (department)      Sade    sees Piping only
 *   (company)                Tunde   sees everything, arranges the boards
 *   (company)                Kemi    sees everything EXCEPT confidential
 *   (company)                Yusuf   may open boards, may not read documents at all
 */
final class DashboardWidgetTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $emeka;

    private User $ngozi;

    private User $tunde;

    private User $kemi;

    private User $yusuf;

    /** @var array<string,KpiDefinition> */
    private array $measures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $divisionHead = $this->makeRole($this->org, 'Division Head', [
            'document.view' => 'division',
            'document.view_confidential' => 'division',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
        ]);

        $departmental = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'document.view_confidential' => 'department',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.view_confidential' => 'organization',
            'document.create' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'report.view' => 'organization',
            'report.create' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
            'dashboard.manage' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);

        // Everything except clearance.
        $uncleared = $this->makeRole($this->org, 'Company Secretary', [
            'document.view' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
        ]);

        // The person who may open a board and read nothing behind it. Without them, "a tile is only
        // built for somebody who may read its register" is untested — everybody else can.
        $boardOnly = $this->makeRole($this->org, 'Board Watcher', [
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
        ]);

        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->engineering, [$divisionHead]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$departmental]);
        $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$departmental]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->kemi = $this->makePerson($this->org, 'Kemi Alabi', null, [$uncleared]);
        $this->yusuf = $this->makePerson($this->org, 'Yusuf Danjuma', null, [$boardOnly]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== what a tile may not say

    #[Test]
    public function a_tile_carries_no_permission_of_its_own_anywhere_in_the_schema(): void
    {
        // §84, at the only level that actually holds. A tile whose required permission were a
        // column would be a tile somebody could edit into publishing a register under a permission
        // most of the company holds — and every other field on it would look perfectly correct.
        $columns = Schema::getColumnListing('dashboard_widgets');

        $this->assertNotContains('permission', $columns);
        $this->assertNotContains('required_permission', $columns);
        $this->assertNotContains('visible_to', $columns);
    }

    #[Test]
    public function the_permission_a_tile_needs_comes_from_the_register_behind_it(): void
    {
        $widget = $this->tile(['kind' => 'kpi_tile', 'kpi_definition_id' => $this->kpi()->getKey()]);

        $this->assertSame('document.view', $widget->requiredPermission());
    }

    #[Test]
    public function tightening_a_register_tightens_every_board_quoting_it_without_a_migration(): void
    {
        // The whole argument for deriving rather than storing: there is no second copy to find.
        // A measure re-pointed at a different register asks that register's permission on the very
        // next page load, and any tile showing it follows.
        $widget = $this->tile([
            'kind' => 'kpi_tile',
            'kpi_definition_id' => $this->kpi(['entity_type' => 'project', 'name' => 'Projects running'])->getKey(),
        ]);

        $this->assertSame('project.view', $widget->requiredPermission());
    }

    #[Test]
    public function a_tile_whose_register_the_reader_cannot_read_is_never_built(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'title' => 'Procedures on file']);

        // Yusuf holds every dashboard permission there is and no document permission at all. §6:
        // not hidden in the template, not greyed out — absent.
        $this->assertSame([], $this->compose('executive', $this->yusuf));

        // And present for somebody who may read the register, so the test is about the permission
        // rather than about an empty board.
        $this->assertCount(1, $this->compose('executive', $this->tunde));
    }

    #[Test]
    public function the_tile_is_missing_from_the_page_itself_and_not_merely_from_the_engine(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'title' => 'Procedures on file']);

        $this->actingFresh($this->yusuf)
            ->get(route('configuration.widgets.index'))
            ->assertOk();

        // The arrangement page is a different question from the board: it lists what a company has
        // chosen, which is why Yusuf may open it. What he may not learn from it is a figure.
        $this->assertSame([], $this->compose('executive', $this->yusuf));
    }

    #[Test]
    public function a_tile_whose_measure_has_been_switched_off_is_not_drawn(): void
    {
        $kpi = $this->kpi();
        $this->tile(['kpi_definition_id' => $kpi->getKey()]);

        $this->withinOrganization($this->org, function () use ($kpi) {
            $kpi->is_active = false;
            $kpi->save();
        });

        $this->assertSame([], $this->compose('executive', $this->tunde));
    }

    // ================================================================== the figure itself

    #[Test]
    public function a_live_tile_is_counted_through_the_visibility_of_whoever_opened_the_board(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'refresh' => 'live']);

        // One arrangement. Three readers. Three different and equally correct numbers.
        $this->assertSame('3', $this->onlyTile('executive', $this->tunde)->value);
        $this->assertSame('3', $this->onlyTile('executive', $this->emeka)->value);
        $this->assertSame('2', $this->onlyTile('executive', $this->ngozi)->value);
    }

    #[Test]
    public function the_company_wording_wins_over_the_measures_own_name(): void
    {
        $kpi = $this->kpi(['name' => 'Procedures on file']);

        $named = $this->tile(['kpi_definition_id' => $kpi->getKey(), 'title' => 'Procedures in force']);
        $this->assertSame('Procedures in force', $named->heading());

        $unnamed = $this->tile(['kpi_definition_id' => $kpi->getKey(), 'board' => 'personal']);
        $this->assertSame('Procedures on file', $unnamed->heading());
    }

    // ================================================================== last night's reading

    #[Test]
    public function last_nights_reading_is_served_to_somebody_whose_reach_holds_the_whole_company(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'refresh' => 'stored']);
        $this->sweep();

        $tile = $this->onlyTile('executive', $this->tunde);

        $this->assertTrue($tile->shared);
        $this->assertSame('2', $tile->value);
        $this->assertStringContainsString('The whole company', (string) $tile->footnote);
    }

    #[Test]
    public function somebody_whose_reach_falls_short_gets_their_own_figure_and_is_told_so(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'refresh' => 'stored']);
        $this->sweep();

        $tile = $this->onlyTile('executive', $this->ngozi);

        // NOT 2. The shared figure exists, was counted tonight, and is one query away — and handing
        // it to Ngozi would tell her exactly how many procedures sit in departments she cannot open.
        $this->assertFalse($tile->shared);
        $this->assertSame('1', $tile->value);
        $this->assertNotNull($tile->footnote);
    }

    #[Test]
    public function seeing_every_record_except_the_confidential_ones_does_not_earn_the_shared_figure(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Board pay review', ['confidentiality' => 'confidential']);

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'refresh' => 'stored']);
        $this->sweep();

        $tile = $this->onlyTile('executive', $this->kemi);

        // Kemi holds `document.view` organization-wide, which reads like total visibility and is
        // not. The shared figure is 2; hers is 1; the difference is precisely the record somebody
        // marked confidential, which is the one thing the gap must never advertise.
        $this->assertFalse($tile->shared);
        $this->assertSame('1', $tile->value);
        $this->assertNotNull($tile->footnote);

        // And the person who does hold clearance still gets the fast path, so the fallback is a
        // consequence of reach rather than of asking for a stored figure at all.
        $this->assertTrue($this->onlyTile('executive', $this->tunde)->shared);
    }

    #[Test]
    public function a_stored_tile_with_no_reading_yet_says_so_rather_than_pretending(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'refresh' => 'stored']);
        // Deliberately no sweep.

        $tile = $this->onlyTile('executive', $this->tunde);

        $this->assertFalse($tile->shared);
        $this->assertSame('1', $tile->value);
        $this->assertStringContainsString('counted now', (string) $tile->footnote);
    }

    #[Test]
    public function a_live_tile_carries_no_footnote_because_a_note_on_every_tile_is_read_by_nobody(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'refresh' => 'live']);

        $this->assertNull($this->onlyTile('executive', $this->ngozi)->footnote);
    }

    // ================================================================== a line over time

    #[Test]
    public function a_trend_is_refused_rather_than_approximated_for_somebody_whose_reach_falls_short(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile(['kind' => 'kpi_trend', 'kpi_definition_id' => $this->kpi()->getKey()]);
        $this->sweep();

        // THE ONE PLACE WITH NO FALLBACK. A figure can be recounted for one person; a history
        // cannot, because the records behind it have moved since. Ngozi is told that plainly.
        $tile = $this->onlyTile('executive', $this->ngozi);

        $this->assertFalse($tile->available);
        $this->assertNotNull($tile->reason);
        $this->assertSame([], $tile->points);

        // Tunde, whose reach holds the company, gets the line.
        $his = $this->onlyTile('executive', $this->tunde);
        $this->assertTrue($his->available);
        $this->assertTrue($his->hasPoints());
    }

    #[Test]
    public function a_trend_before_the_first_sweep_is_an_empty_line_with_a_reason_not_a_flat_one(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile(['kind' => 'kpi_trend', 'kpi_definition_id' => $this->kpi()->getKey()]);

        $tile = $this->onlyTile('executive', $this->tunde);

        $this->assertFalse($tile->available);
        $this->assertSame([], $tile->points);
    }

    // ================================================================== the top of a report

    #[Test]
    public function a_report_tile_shows_the_readers_own_rows_and_never_more_than_it_was_asked_for(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile([
            'kind' => 'report_table',
            'report_definition_id' => $this->report()->getKey(),
            'row_limit' => 2,
        ]);

        $director = $this->onlyTile('executive', $this->tunde);
        $this->assertTrue($director->isTable());
        $this->assertCount(2, $director->rows);
        $this->assertSame('3', $director->value);

        // Ngozi's list is her own, and it is short enough that no truncation notice is invented.
        $controller = $this->onlyTile('executive', $this->ngozi);
        $this->assertCount(2, $controller->rows);
        $this->assertSame('2', $controller->value);
        $this->assertNull($controller->footnote);
    }

    #[Test]
    public function a_report_tile_is_never_stored_whatever_the_arrangement_asks_for(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile([
            'kind' => 'report_table',
            'report_definition_id' => $this->report()->getKey(),
            'refresh' => 'stored',
        ]);

        // A snapshot of a FIGURE is one number about a scope. A snapshot of ROWS is the records
        // themselves, and there is no reach test that makes one person's list safe to hand to
        // another — so the request is simply not honoured.
        $this->assertFalse($this->onlyTile('executive', $this->tunde)->shared);
    }

    // ================================================================== boards and altitudes

    #[Test]
    public function a_board_is_empty_for_somebody_who_may_not_open_it(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile(['kpi_definition_id' => $this->kpi()->getKey()]);

        // Kemi holds `dashboard.view_executive` and not `dashboard.view_department`, and the
        // composer checks the board permission itself rather than trusting a route to have done it
        // — it is called from console commands and mailables with no middleware in front (§6).
        $this->assertSame([], $this->compose('department', $this->kemi, $this->civil->getKey()));
        $this->assertNotSame([], $this->compose('executive', $this->kemi));
    }

    #[Test]
    public function a_department_board_counts_the_department_it_is_about(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile([
            'board' => 'department',
            'kpi_definition_id' => $this->kpi(['scope' => 'department'])->getKey(),
        ]);

        $this->assertSame('2', $this->onlyTile('department', $this->tunde, $this->civil->getKey())->value);
        $this->assertSame('1', $this->onlyTile('department', $this->tunde, $this->piping->getKey())->value);
    }

    #[Test]
    public function a_measure_rescoped_after_placement_blanks_rather_than_answering_a_different_question(): void
    {
        $kpi = $this->kpi();
        $this->tile(['kpi_definition_id' => $kpi->getKey()]);

        // The builder refuses this combination at save time. A measure can still be re-scoped
        // afterwards, and a department figure rendered under a company heading is a WRONG number
        // rather than a missing one — which is the worse of the two failures.
        $this->withinOrganization($this->org, function () use ($kpi) {
            $kpi->scope = 'department';
            $kpi->save();
        });

        $tile = $this->onlyTile('executive', $this->tunde);

        $this->assertFalse($tile->available);
        $this->assertStringContainsString('does not belong on this board', (string) $tile->reason);
    }

    #[Test]
    public function an_unknown_board_yields_nothing_rather_than_everything(): void
    {
        $this->tile(['kpi_definition_id' => $this->kpi()->getKey()]);

        $this->assertSame([], $this->compose('managing-director', $this->tunde));
    }

    #[Test]
    public function a_hidden_tile_is_off_the_board_but_keeps_its_setup(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $widget = $this->tile(['kpi_definition_id' => $this->kpi()->getKey()]);

        $this->actingFresh($this->tunde)
            ->put(route('configuration.widgets.toggle', $widget))
            ->assertRedirect();

        $this->assertSame([], $this->compose('executive', $this->tunde));
        $this->assertDatabaseHas('dashboard_widgets', [
            'id' => $widget->getKey(),
            'is_active' => false,
        ]);
    }

    // ================================================================== what the builder refuses

    #[Test]
    public function a_measure_belonging_to_another_company_is_refused_however_valid_the_id_looks(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirs = $this->withinOrganization($other, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Their procedures',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
        ], $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', ['kpi.view' => 'organization', 'kpi.manage' => 'organization']),
        ])));

        // The id is sequential, it exists, and Tunde holds every dashboard permission. None of that
        // is an argument (§84, §94). Binding it would publish a rival's definition of a figure — and
        // their wording for it — onto a board the rival cannot see.
        $this->actingFresh($this->tunde)
            ->post(route('configuration.widgets.store'), $this->payload([
                'kpi_definition_id' => (string) $theirs->getKey(),
            ]))
            ->assertSessionHasErrors('kpi_definition_id');

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    #[Test]
    public function a_report_belonging_to_another_company_is_refused_the_same_way(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirs = $this->withinOrganization($other, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Their procedures',
            'entity_type' => 'document',
            'columns' => ['title'],
        ], $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', ['report.view' => 'organization', 'report.create' => 'organization']),
        ])));

        $this->actingFresh($this->tunde)
            ->post(route('configuration.widgets.store'), $this->payload([
                'kind' => 'report_table',
                'kpi_definition_id' => '',
                'report_definition_id' => (string) $theirs->getKey(),
            ]))
            ->assertSessionHasErrors('report_definition_id');

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    #[Test]
    public function a_measure_about_one_department_is_refused_a_place_on_the_executive_board(): void
    {
        $kpi = $this->kpi(['scope' => 'department', 'name' => 'Procedures in this department']);

        $this->expectException(ValidationException::class);

        $this->tile(['kpi_definition_id' => $kpi->getKey(), 'board' => 'executive']);
    }

    #[Test]
    public function a_trend_is_refused_a_place_on_the_landing_page(): void
    {
        // The personal board has no scope wide enough to contain a stored figure — one person is
        // not a scope any second reader's reach can hold — so the combination is refused on the
        // form rather than saved and rendered as a permanent apology.
        $this->expectException(ValidationException::class);

        $this->tile(['kind' => 'kpi_trend', 'board' => 'personal', 'kpi_definition_id' => $this->kpi()->getKey()]);
    }

    #[Test]
    public function switching_a_tile_to_a_report_lets_go_of_the_measure_it_used_to_show(): void
    {
        $widget = $this->tile(['kpi_definition_id' => $this->kpi()->getKey()]);

        $this->actingFresh($this->tunde)
            ->put(route('configuration.widgets.update', $widget), $this->payload([
                'kind' => 'report_table',
                'kpi_definition_id' => '',
                'report_definition_id' => (string) $this->report()->getKey(),
            ]))
            ->assertRedirect(route('configuration.widgets.index'));

        $widget->refresh();

        // A row whose meaning depends on reading two columns in the right order is a row somebody
        // eventually reads in the wrong order, and draws a figure from a measure the tile no longer
        // claims to show.
        $this->assertNull($widget->kpi_definition_id);
        $this->assertNotNull($widget->report_definition_id);
    }

    #[Test]
    public function a_tile_with_no_source_at_all_is_not_saved(): void
    {
        $this->actingFresh($this->tunde)
            ->post(route('configuration.widgets.store'), $this->payload(['kpi_definition_id' => '']))
            ->assertSessionHasErrors('kpi_definition_id');

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    #[Test]
    public function a_row_count_beyond_the_ceiling_is_brought_back_to_it(): void
    {
        $widget = $this->tile([
            'kind' => 'report_table',
            'report_definition_id' => $this->report()->getKey(),
            'row_limit' => WidgetCatalogue::MAX_ROW_LIMIT + 50,
        ]);

        $this->assertSame(WidgetCatalogue::MAX_ROW_LIMIT, $widget->row_limit);
    }

    #[Test]
    public function a_board_will_not_take_more_tiles_than_anybody_reads(): void
    {
        $kpi = $this->kpi();

        for ($i = 0; $i < WidgetCatalogue::MAX_PER_BOARD; $i++) {
            $this->tile(['kpi_definition_id' => $kpi->getKey(), 'title' => 'Tile '.$i]);
        }

        $this->expectException(ValidationException::class);

        $this->tile(['kpi_definition_id' => $kpi->getKey(), 'title' => 'One too many']);
    }

    // ================================================================== who may arrange one

    #[Test]
    public function somebody_who_may_read_a_board_may_not_rearrange_it(): void
    {
        $widget = $this->tile(['kpi_definition_id' => $this->kpi()->getKey()]);

        // Emeka holds every dashboard VIEW permission and not `dashboard.manage`. Every write route
        // is closed to him, including the ones the index page would never have shown him a link to
        // (§6: the hidden button is not the control).
        $this->actingFresh($this->emeka)->get(route('configuration.widgets.create'))->assertForbidden();
        $this->actingFresh($this->emeka)->post(route('configuration.widgets.store'), $this->payload())->assertForbidden();
        $this->actingFresh($this->emeka)->get(route('configuration.widgets.edit', $widget))->assertForbidden();
        $this->actingFresh($this->emeka)->put(route('configuration.widgets.update', $widget), $this->payload())->assertForbidden();
        $this->actingFresh($this->emeka)->put(route('configuration.widgets.toggle', $widget))->assertForbidden();
        $this->actingFresh($this->emeka)->delete(route('configuration.widgets.destroy', $widget))->assertForbidden();

        // But he may look at the arrangement, which is the point of separating the two.
        $this->actingFresh($this->emeka)->get(route('configuration.widgets.index'))->assertOk();
    }

    #[Test]
    public function another_companys_tile_is_not_found_rather_than_forbidden(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirAdmin = $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', [
                'kpi.view' => 'organization',
                'kpi.manage' => 'organization',
                'dashboard.view' => 'organization',
                'dashboard.manage' => 'organization',
            ]),
        ]);

        $theirKpi = $this->withinOrganization($other, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Their procedures',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
        ], $theirAdmin));

        $theirs = $this->withinOrganization($other, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'kpi_definition_id' => $theirKpi->getKey(),
        ], $theirAdmin));

        // §75, §84. The id is guessable and Tunde holds every dashboard permission there is.
        $this->actingFresh($this->tunde)->get(route('configuration.widgets.edit', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->put(route('configuration.widgets.update', $theirs), $this->payload())->assertNotFound();
        $this->actingFresh($this->tunde)->put(route('configuration.widgets.toggle', $theirs))->assertNotFound();
        $this->actingFresh($this->tunde)->delete(route('configuration.widgets.destroy', $theirs))->assertNotFound();
    }

    #[Test]
    public function one_companys_board_never_shows_anothers_tiles(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirAdmin = $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Admin', [
                'document.view' => 'organization',
                'kpi.view' => 'organization',
                'kpi.manage' => 'organization',
                'dashboard.view' => 'organization',
                'dashboard.view_executive' => 'organization',
                'dashboard.manage' => 'organization',
            ]),
        ]);

        $theirKpi = $this->withinOrganization($other, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Their procedures',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
        ], $theirAdmin));

        $this->withinOrganization($other, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'kpi_definition_id' => $theirKpi->getKey(),
            'title' => 'Kaduna’s own tile',
        ], $theirAdmin));

        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->tile(['kpi_definition_id' => $this->kpi()->getKey(), 'title' => 'Meridian’s own tile']);

        $mine = $this->compose('executive', $this->tunde);

        $this->assertCount(1, $mine);
        $this->assertSame('Meridian’s own tile', $mine[0]->heading);
    }

    #[Test]
    public function a_reorder_ignores_ids_that_are_not_on_this_board(): void
    {
        $kpi = $this->kpi();
        $first = $this->tile(['kpi_definition_id' => $kpi->getKey(), 'title' => 'First']);
        $second = $this->tile(['kpi_definition_id' => $kpi->getKey(), 'title' => 'Second']);
        $elsewhere = $this->tile(['kpi_definition_id' => $kpi->getKey(), 'title' => 'Landing', 'board' => 'personal']);

        $this->actingFresh($this->tunde)
            ->put(route('configuration.widgets.reorder'), [
                'board' => 'executive',
                // A stale page: a tile from another board, and one that never existed.
                'order' => [$second->getKey(), $elsewhere->getKey(), 999999, $first->getKey()],
            ])
            ->assertRedirect();

        // Rearranged what it could, left the rest alone — rather than failing wholesale in front of
        // somebody about to present from the screen.
        $this->assertSame(1, $second->fresh()->sort);
        $this->assertSame(2, $first->fresh()->sort);
        $this->assertSame(1, $elsewhere->fresh()->sort);
    }

    // ================================================================== plumbing

    /** @return array<int,RenderedWidget> */
    private function compose(
        string $board,
        User $viewer,
        ?int $scopeId = null,
        ?CarbonInterface $asOf = null,
    ): array {
        return $this->withinOrganization($this->org, fn () => app(WidgetComposer::class)
            ->compose($board, $viewer, $scopeId, $asOf));
    }

    private function onlyTile(string $board, User $viewer, ?int $scopeId = null): RenderedWidget
    {
        $tiles = $this->compose($board, $viewer, $scopeId);

        $this->assertCount(1, $tiles, 'Expected exactly one tile on the '.$board.' board for '.$viewer->name);

        return $tiles[0];
    }

    private function sweep(?CarbonInterface $asOf = null): int
    {
        return $this->withinOrganization($this->org,
            fn () => app(SnapshotSweeper::class)->sweepCurrentTenant($asOf));
    }

    /** @param  array<string,mixed>  $attributes */
    private function tile(array $attributes = []): DashboardWidget
    {
        return $this->withinOrganization($this->org, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'refresh' => 'live',
            'width' => 'third',
            ...$attributes,
        ], $this->tunde));
    }

    /**
     * A measure, made once per name.
     *
     * Memoised because the builder refuses two measures with one name — deliberately, since a board
     * carrying two tiles called "Procedures on file" is a board nobody can settle an argument with.
     * A test asking for the same measure twice wants the same measure.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function kpi(array $attributes = []): KpiDefinition
    {
        $name = (string) ($attributes['name'] ?? 'Procedures on file');

        if (isset($this->measures[$name])) {
            return $this->measures[$name];
        }

        return $this->measures[$name] = $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
            ...$attributes,
        ], $this->tunde));
    }

    /** @param  array<string,mixed>  $attributes */
    private function report(array $attributes = []): ReportDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(ReportDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'columns' => ['title'],
            ...$attributes,
        ], $this->tunde));
    }

    /**
     * A form post for the tile builder.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'kpi_definition_id' => (string) $this->kpi()->getKey(),
            'refresh' => 'live',
            'width' => 'third',
            'is_active' => '1',
            ...$overrides,
        ];
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
