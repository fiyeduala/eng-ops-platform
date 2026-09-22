<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Engines\Report\ExecutiveBriefing;
use App\Models\DocumentCategory;
use App\Models\KpiDefinition;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DashboardWidgetService;
use App\Services\KpiDefinitionService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\Support\CountsQueries;
use Tests\TestCase;

/**
 * What the heavy pages cost, and whether that cost is a function of the row count (§89, §95).
 *
 * THE BUG THIS FILE EXISTS FOR IS ALREADY HALF-CAUGHT, WHICH IS EXACTLY WHY THE OTHER HALF IS
 * DANGEROUS. `Model::preventLazyLoading()` is on outside production (AppServiceProvider:465), so
 * the accidental N+1 — a blade reaching through an unloaded relation inside a foreach — throws on
 * the first row and never reaches a reviewer. That is real protection, and it has an edge: it sees
 * relations. It does not see a permission asked once per row, a status vocabulary re-read per
 * record, a numbering format fetched inside a loop, a policy that opens its own query, or a
 * presenter that resolves a label by going back to the database. Those are ordinary, explicit,
 * legal statements. They cost nothing on the three-row fixture every other test in this suite
 * builds, and they are the entire cost of a register with two hundred rows on it — which is the
 * only size that exists in production.
 *
 * SO THE CEILINGS ARE NOT THE POINT. A budget of "at most 12 queries" is a fact about today's
 * eager-load list and it will need editing the first time a column is added; kept alone it teaches
 * a maintainer that the way to pass this file is to raise a number. The assertions that matter are
 * the growth ones: the same page, the same reader, six records and then eighteen, and the SAME
 * QUERY COUNT. That claim survives every honest refactor and fails only for the bug. The ceilings
 * sit alongside it to catch a regression early and to make the failure arrive with the offending
 * statement printed rather than as a slow page somebody notices in a year.
 *
 * WHAT IS ALLOWED TO GROW, SAID OUT LOUD. A board's cost grows with the number of TILES, because
 * each tile is a distinct measure over a distinct register and there is no honest way to answer
 * four questions with one query. A daily briefing's cost grows with the number of RECIPIENTS,
 * because §55 requires every recipient's figures to be narrowed to what that person may see, and
 * a shared figure would be the leak. Both of those are bounded by configuration a company sets
 * once. Neither may grow with the number of records, and that is what is asserted here.
 *
 * AND THE METER IS TESTED FIRST. A query counter that silently records nothing turns every budget
 * in this file into a test that passes hardest when it is most broken — the same failure shape the
 * rest of Phase 9 is about — so the first test points the meter at a known number of statements
 * and at a closure that runs none.
 *
 *   Civil (department)      Ngozi   Civil only
 *   (company)               Tunde   everything, and arranges the boards
 */
final class QueryBudgetTest extends TestCase
{
    use BuildsTenants, CountsQueries, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    /**
     * How many records the small fixture carries, and how many are added for the growth check.
     *
     * Both totals stay under one page of 25. A growth test whose second fixture spills onto page
     * two would compare a full page with a full page, measure nothing, and pass forever.
     */
    private const SMALL = 6;

    private const EXTRA = 12;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $tunde;

    private User $ngozi;

    /** @var array<string,KpiDefinition> */
    private array $measures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.view_confidential' => 'organization',
            'document.create' => 'organization',
            'task.view' => 'organization',
            'task.create' => 'organization',
            'project.view' => 'organization',
            'org_unit.view' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
            'dashboard.manage' => 'organization',
        ]);

        $departmental = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'task.view' => 'department',
            'org_unit.view' => 'department',
            'dashboard.view' => 'organization',
            'dashboard.view_department' => 'organization',
        ]);

        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$departmental]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== the meter itself

    #[Test]
    public function the_meter_counts_the_statements_it_is_pointed_at_and_no_others(): void
    {
        // Three statements, written out rather than generated, because the number this assertion
        // depends on has to be readable without running anything.
        $tally = $this->measureQueries(function (): void {
            DB::table('organizations')->count();
            DB::table('org_units')->count();
            DB::table('users')->count();
        });

        $this->assertSame(3, $tally->count(),
            'The meter did not see three statements it was pointed straight at. Every budget below '
            .'this line is worthless until it does.');

        // The fixture above ran hundreds of statements and none of them belong to that closure.
        $this->assertSame([], $this->measureQueries(fn () => null)->all(),
            'A measurement that ran no queries came back with some, which means the sink is being '
            .'filled outside the closure and every budget below is counting the wrong work.');

        // And a second measurement does not inherit the first. A listener left attached, or a sink
        // that accumulated, would make each measurement in a method more expensive than the last
        // and every ceiling here would be measuring the test rather than the page.
        $again = $this->measureQueries(fn () => DB::table('organizations')->count());

        $this->assertSame(1, $again->count(),
            'A later measurement in the same test method counted an earlier one.');
    }

    #[Test]
    public function the_meter_names_the_statement_that_repeated(): void
    {
        $tally = $this->measureQueries(function (): void {
            foreach (range(1, 5) as $ignored) {
                DB::table('org_units')->where('id', $ignored)->count();
            }
        });

        $this->assertSame(5, $tally->worstRepeat(),
            'Five identical statements did not group. Laravel hands the listener parameterised SQL, '
            .'so they should be byte-identical; if they are not, the ids are being inlined and every '
            .'repeat count in this file is reading 1.');

        $this->assertStringContainsString('5x', $tally->report(),
            'The report did not lead with the repeat. A budget failure that prints a total and not '
            .'the offending statement tells a maintainer to raise the number.');
    }

    // ================================================================== the registers

    #[Test]
    public function the_document_register_costs_the_same_for_six_rows_as_for_eighteen(): void
    {
        $this->documents(self::SMALL);

        $this->assertCostDoesNotGrowWith(
            grow: fn (int $extra) => $this->documents($extra, 'Extra'),
            work: fn () => $this->actingFresh($this->tunde)
                ->get(route('documents.index'))
                ->assertOk()
                ->assertSee('Procedure 1'),
            extra: self::EXTRA,
            because: 'The document register is the page this platform is most often looked at through, '
                .'and the one most likely to acquire a per-row lookup: a status label, a category '
                .'code, a "can this reader open it" check. Its cost must be a function of the '
                .'eager-load list and nothing else.',
        );
    }

    #[Test]
    public function the_document_register_stays_inside_its_budget(): void
    {
        $this->documents(self::SMALL + self::EXTRA);

        $documents = fn () => $this->actingFresh($this->tunde)
            ->get(route('documents.index'))
            ->assertOk();

        $this->warmUp($documents);

        $tally = $this->measureQueries($documents);

        $tally->assertWithin(self::DOCUMENT_INDEX_BUDGET,
            'One page of the document register: the session, the reader\'s permissions, the page of '
            .'records with its eager loads, and the filter dropdowns. Every one of those is a fixed '
            .'cost that does not move when a row is added.');
    }

    #[Test]
    public function the_document_register_asks_nothing_once_per_row(): void
    {
        $this->documents(self::SMALL + self::EXTRA);

        $documents = fn () => $this->actingFresh($this->tunde)
            ->get(route('documents.index'))
            ->assertOk();

        $this->warmUp($documents);

        $tally = $this->measureQueries($documents);

        // Eighteen rows on the page. Measured, this page repeats nothing at all — eight queries,
        // eight distinct statements — so the ceiling is three rather than one: room for a lookup a
        // page might legitimately ask twice, and far enough below eighteen that a per-row query
        // cannot hide underneath it.
        $tally->assertNoStatementRepeatedMoreThan(3,
            'Eighteen documents are on this page. A statement running once per row is the shape '
            .'preventLazyLoading() cannot see, because an explicit query is not a lazy load.');
    }

    #[Test]
    public function the_task_register_costs_the_same_for_six_rows_as_for_eighteen(): void
    {
        $this->tasks(self::SMALL);

        $this->assertCostDoesNotGrowWith(
            grow: fn (int $extra) => $this->tasks($extra, 'Extra'),
            work: fn () => $this->actingFresh($this->tunde)
                ->get(route('tasks.index'))
                ->assertOk(),
            extra: self::EXTRA,
            because: 'Tasks carry an assignee, a status, a project and a follower count, which is four '
                .'more chances to resolve something per row than a document has.',
        );
    }

    #[Test]
    public function a_narrower_reader_does_not_pay_more_to_see_less(): void
    {
        $this->documents(self::SMALL);

        $asDirector = fn () => $this->actingFresh($this->tunde)
            ->get(route('documents.index'))->assertOk();

        $asController = fn () => $this->actingFresh($this->ngozi)
            ->get(route('documents.index'))->assertOk();

        $this->warmUp($asDirector);
        $this->warmUp($asController);

        $wide = $this->measureQueries($asDirector);
        $narrow = $this->measureQueries($asController);

        // Not equality: the two readers hold different grants and the scope compiler legitimately
        // resolves a department reader's reach with an extra lookup the company-wide reader does
        // not need. What must not happen is the cost of narrowing scaling with anything — a
        // department reader paying several times a director's bill is a visibility check being
        // asked per record rather than folded into the query.
        $this->assertLessThanOrEqual(
            $wide->count() + 4,
            $narrow->count(),
            "A department reader paid materially more than a company-wide reader for a shorter page.\n"
            ."Narrowing belongs in the builder; if it is costing per record it is being applied after\n"
            ."the rows come back, which is also how a row somebody may not see gets fetched first and\n"
            ."filtered second (§6).\n\nDIRECTOR\n".$wide->report()."\n\nDEPARTMENT\n".$narrow->report(),
        );
    }

    // ================================================================== the boards (§89)

    #[Test]
    public function the_executive_board_costs_the_same_however_many_records_its_tiles_count(): void
    {
        $this->documents(self::SMALL);
        $this->tile($this->companyMeasure());

        $this->assertCostDoesNotGrowWith(
            grow: fn (int $extra) => $this->documents($extra, 'Extra'),
            work: fn () => $this->actingFresh($this->tunde)
                ->get(route('boards.executive'))
                ->assertOk(),
            extra: self::EXTRA,
            because: 'A tile is an aggregate. Its cost is one query whatever it is counting, and a '
                .'board whose price moves when a record is added is a tile that fetched rows in '
                .'order to count them.',
        );
    }

    #[Test]
    public function a_board_costs_one_measure_per_tile_and_not_more(): void
    {
        $this->documents(self::SMALL);

        $board = fn () => $this->actingFresh($this->tunde)
            ->get(route('boards.executive'))->assertOk();

        $this->warmUp($board);

        $bare = $this->measureQueries($board);

        $this->tile($this->companyMeasure());
        $this->tile($this->secondCompanyMeasure());

        // Warmed again: arranging the board is a write, and the first load after one repays
        // cache costs that belong to the write rather than to the tiles.
        $this->warmUp($board);

        $two = $this->measureQueries($board);

        // Tiles ARE allowed to cost. Four questions cannot honestly be answered by one query, and
        // the number of tiles is configuration a company sets once, not a number that grows by
        // itself. What is asserted is the rate: a handful of statements per tile, not a page of
        // them, and certainly not a re-read of the board's own definition for each one.
        $perTile = intdiv($two->count() - $bare->count(), 2);

        $this->assertLessThanOrEqual(
            self::QUERIES_PER_TILE,
            $perTile,
            sprintf(
                "Each tile added to this board costs %d queries.\n"
                ."A tile is a measure: it should be the aggregate, and whatever single lookup the\n"
                ."scope needs. A tile costing many is a definition, a catalogue or a scope being\n"
                ."re-read inside the loop that renders them.\n\nEMPTY BOARD\n%s\n\nTWO TILES\n%s",
                $perTile, $bare->report(), $two->report(),
            ),
        );
    }

    #[Test]
    public function the_drill_down_under_a_tile_costs_the_same_for_six_rows_as_for_eighteen(): void
    {
        $this->documents(self::SMALL);

        $measure = $this->companyMeasure();

        // §89 names this path directly: the step from a figure to the records behind it is where a
        // dashboard stops being a picture, and it is the one place in the platform that runs a
        // register's query and a KPI's aggregate in the same request.
        $this->assertCostDoesNotGrowWith(
            grow: fn (int $extra) => $this->documents($extra, 'Extra'),
            work: fn () => $this->actingFresh($this->tunde)
                ->get(route('drilldown.measure', $measure))
                ->assertOk(),
            extra: self::EXTRA,
            because: 'The drill-down prints a generic list, which means it resolves its columns from a '
                .'catalogue rather than from a blade written for one register. A column resolved per '
                .'row instead of per page is the obvious way to write that and costs nothing until '
                .'the list is long.',
        );
    }

    // ================================================================== the daily report (§55)

    #[Test]
    public function a_briefing_costs_the_same_whatever_it_is_summarising(): void
    {
        $this->documents(self::SMALL);
        $this->tile($this->companyMeasure());

        $briefing = app(ExecutiveBriefing::class);
        $asOf = now();

        // Per RECIPIENT is allowed to cost — §55 requires each person's figures to be narrowed to
        // what that person may see, and one shared figure mailed to everybody is exactly the leak
        // §68 forbids. Per RECORD is not allowed to cost anything, and that is the half measured
        // here: the same recipient, the same tiles, three times the records.
        $this->assertCostDoesNotGrowWith(
            grow: fn (int $extra) => $this->documents($extra, 'Extra'),
            work: fn () => $this->withinOrganization($this->org,
                fn () => $briefing->tilesFor($this->tunde, $asOf)),
            extra: self::EXTRA,
            because: 'The briefing runs unattended in the small hours against a company\'s whole year '
                .'of records. It is the one heavy path nobody is watching, so it is the one where a '
                .'per-record query turns into a cron job that never finishes rather than a page '
                .'somebody complains about.',
        );
    }

    #[Test]
    public function the_recipient_list_is_settled_in_one_pass(): void
    {
        $this->documents(self::SMALL);

        // Four more people who may open the board. Working out who receives the report must not
        // become a permission check asked once per employee — the list is drawn from a single
        // subquery against the permission catalogue (ExecutiveBriefing::recipients()), and the
        // company this runs for has hundreds of staff.
        foreach (['Adaeze Okonkwo', 'Sani Bala', 'Femi Ajayi', 'Grace Udo'] as $name) {
            $this->makePerson($this->org, $name, null, [
                $this->makeRole($this->org, 'Board Reader '.$name, [
                    'dashboard.view' => 'organization',
                    'dashboard.view_executive' => 'organization',
                    'document.view' => 'organization',
                ]),
            ]);
        }

        $briefing = app(ExecutiveBriefing::class);

        $tally = $this->measureQueries(fn () => $this->withinOrganization($this->org,
            fn () => $briefing->recipients()));

        $tally->assertWithin(self::RECIPIENTS_BUDGET,
            'Resolving who gets the daily report is one question against the permission catalogue, '
            .'not one question per member of staff.');
    }

    // ================================================================== budgets

    /**
     * The ceilings, gathered here rather than scattered through the tests above.
     *
     * Each was measured, not chosen, and each sits a little above the measurement so that an
     * ordinary change — one more eager load, one more dropdown — does not fail the suite. A number
     * raised to make a red test green, with no other change beside it, is the thing this file was
     * written to make visible; the growth assertions above are what stop that from working.
     *
     * Measured on 2026-09-22, all against a WARM cache (see CountsQueries::warmUp), because that
     * is the state every request after the first one in a process is in:
     *
     *   document register, 18 rows   8 queries, all distinct
     *   each further board tile      1 query  (2 tiles cost 3, one of which is the widget list)
     *   the daily report's audience  4 queries, all distinct, for five recipients
     */
    private const DOCUMENT_INDEX_BUDGET = 12;

    private const QUERIES_PER_TILE = 2;

    private const RECIPIENTS_BUDGET = 6;

    // ================================================================== fixtures

    private function documents(int $count, string $prefix = 'Procedure'): void
    {
        foreach (range(1, $count) as $n) {
            $this->makeDocument($this->org, $this->tunde, $prefix.' '.$n, [
                'document_category_id' => $this->procedures->getKey(),
                'org_unit_id' => ($n % 2 === 0 ? $this->piping : $this->civil)->getKey(),
            ]);
        }
    }

    private function tasks(int $count, string $prefix = 'Site check'): void
    {
        foreach (range(1, $count) as $n) {
            $this->makeTask($this->org, $this->tunde, $prefix.' '.$n, [
                'org_unit_id' => ($n % 2 === 0 ? $this->piping : $this->civil)->getKey(),
            ]);
        }
    }

    private function tile(KpiDefinition $measure): void
    {
        $this->withinOrganization($this->org, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'refresh' => 'live',
            'width' => 'third',
            'kpi_definition_id' => $measure->getKey(),
        ], $this->tunde));
    }

    private function companyMeasure(): KpiDefinition
    {
        return $this->measures['organization'] ??= $this->measure('Procedures on file', 'organization');
    }

    /**
     * A second company-wide measure, so the two-tile board is two tiles.
     *
     * Company-wide rather than departmental on purpose: DashboardWidgetService refuses a
     * department-scoped measure on the executive board ("is about one department, so it does not
     * belong on the executive board"), which is correct and is not what this test is about.
     */
    private function secondCompanyMeasure(): KpiDefinition
    {
        return $this->measures['organization_2'] ??= $this->measure('Procedures raised this year', 'organization');
    }

    private function measure(string $name, string $scope): KpiDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => $name,
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => $scope,
            'period' => 'all_time',
        ], $this->tunde));
    }
}
