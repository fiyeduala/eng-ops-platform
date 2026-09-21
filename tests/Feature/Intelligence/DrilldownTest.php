<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Document;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The step underneath a figure (§89) — the half of a dashboard that makes the other half worth
 * believing.
 *
 * THE FIGURE AND THE LIST ARE ONE QUERY, and these tests are written to fail if they ever stop
 * being one. A tile that says three and a page that lists three is not interesting on its own;
 * what is interesting is that two people with different reach each get a list matching their OWN
 * number, which is only true because both come from the same builder narrowed by the same
 * `visibleTo`. Counting one way and listing another is the implementation that passes a test
 * written for a single reader and quietly lies to everybody else.
 *
 * AND THE DRILL-DOWN CARRIES CONTEXT, NEVER AUTHORITY (§84, §94). Arriving from a board proves
 * nothing: the register's own read permission is asked again here, and the department id in the
 * query string is proven against the reader's visibility before it narrows anything.
 *
 *   Civil (department)       Ngozi   Civil only — two of the three procedures
 *   Piping (department)              the third
 *   (company)                Tunde   everything, and arranges the boards
 *   (company)                Yusuf   the boards, and nothing the boards count
 */
final class DrilldownTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private OrgUnit $survey;

    private DocumentCategory $procedures;

    private User $ngozi;

    private User $tunde;

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
        $this->survey = $this->makeUnit($this->org, 'Survey', OrgUnitType::Section, $this->civil);

        $departmental = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'org_unit.view' => 'department',
            'dashboard.view' => 'organization',
            'dashboard.view_department' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.view_confidential' => 'organization',
            'document.create' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
            'dashboard.manage' => 'organization',
        ]);

        // The boards and nothing underneath them. This is the person the permission check in the
        // controller exists for: holding `dashboard.view_executive` is entitlement to a page of
        // totals, not to the register those totals were counted from.
        $boardsOnly = $this->makeRole($this->org, 'Board Observer', [
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->survey, [$departmental]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->yusuf = $this->makePerson($this->org, 'Yusuf Danladi', null, [$boardsOnly]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== the figure and its rows

    #[Test]
    public function the_rows_behind_a_figure_are_the_figures_own_query(): void
    {
        $this->threeProcedures();

        $this->actingFresh($this->tunde)->get(route('drilldown.measure', $this->kpi()))
            ->assertOk()
            ->assertSee('Procedures on file')
            ->assertSee('Lifting operations procedure')
            ->assertSee('Scaffolding inspection procedure')
            ->assertSee('Hydrotest procedure');
    }

    #[Test]
    public function two_readers_of_one_figure_get_two_lists_each_matching_their_own_number(): void
    {
        $this->threeProcedures();

        $kpi = $this->kpi();

        $this->tile(['board' => 'executive', 'kpi_definition_id' => $kpi->getKey()]);

        // The company's director: a tile reading three, and three records underneath it.
        $this->actingFresh($this->tunde)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('>3<', false);

        $this->actingFresh($this->tunde)->get(route('drilldown.measure', $kpi))
            ->assertOk()
            ->assertSee('Hydrotest procedure');

        // Ngozi reads the same measure. Her figure is two, and the list she is given is the same
        // two — not three rows with one refused, and not an empty page.
        $this->actingFresh($this->ngozi)->get(route('drilldown.measure', $kpi))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertSee('Scaffolding inspection procedure')
            ->assertDontSee('Hydrotest procedure');
    }

    #[Test]
    public function a_reader_who_can_see_none_of_a_figure_is_told_so_rather_than_shown_a_blank_table(): void
    {
        $this->documentIn($this->piping, 'Hydrotest procedure');

        // Ngozi can count Civil's procedures, of which there are none. "Nothing to list", and a
        // sentence saying somebody else may see more, is an honest empty; a bare table is not.
        $this->actingFresh($this->ngozi)->get(route('drilldown.measure', $this->kpi()))
            ->assertOk()
            ->assertSee('Nothing to list')
            ->assertDontSee('Hydrotest procedure');
    }

    // ================================================================== what is re-asked here

    #[Test]
    public function a_dashboard_permission_does_not_open_the_register_a_tile_counts(): void
    {
        $this->threeProcedures();

        // §6, §84. Yusuf holds `dashboard.view_executive` and no document permission at all. The
        // route lets him past — it guards the drill-down as a dashboard feature — and the
        // controller refuses him, which is the check that has to be server-side.
        $this->actingFresh($this->yusuf)->get(route('drilldown.measure', $this->kpi()))
            ->assertForbidden();

        $this->actingFresh($this->tunde)->get(route('drilldown.measure', $this->kpi()))
            ->assertOk();
    }

    #[Test]
    public function a_scope_the_reader_cannot_see_is_not_found_rather_than_empty(): void
    {
        $this->threeProcedures();

        $kpi = $this->byDepartment();

        // §94. The id is sequential and the row exists, so an intersection that came back empty
        // would be indistinguishable from "Piping has no procedures" — and repeating it across
        // every id turns the query string into a map of a company Ngozi was never shown.
        $this->actingFresh($this->ngozi)
            ->get(route('drilldown.measure', ['kpiDefinition' => $kpi, 'scope' => $this->piping->getKey()]))
            ->assertNotFound();

        // Her own department opens, so the 404 is about reach rather than about the parameter.
        $this->actingFresh($this->ngozi)
            ->get(route('drilldown.measure', ['kpiDefinition' => $kpi, 'scope' => $this->civil->getKey()]))
            ->assertOk()
            ->assertSee('Lifting operations procedure');
    }

    #[Test]
    public function the_department_a_figure_is_about_travels_in_the_url(): void
    {
        $this->threeProcedures();

        $kpi = $this->byDepartment();

        // docs/05 §8: context comes down the path in the address, and is re-proven at each step.
        // Same measure, same reader, two departments, two lists.
        $this->actingFresh($this->tunde)
            ->get(route('drilldown.measure', ['kpiDefinition' => $kpi, 'scope' => $this->piping->getKey()]))
            ->assertOk()
            ->assertSee('Hydrotest procedure')
            ->assertDontSee('Lifting operations procedure');

        $this->actingFresh($this->tunde)
            ->get(route('drilldown.measure', ['kpiDefinition' => $kpi, 'scope' => $this->civil->getKey()]))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertDontSee('Hydrotest procedure');
    }

    #[Test]
    public function a_scope_id_that_is_not_a_number_is_ignored_rather_than_obeyed(): void
    {
        $this->threeProcedures();

        // The parameter is somebody's suggestion, and this suggestion is nonsense. It falls back
        // to the whole measure rather than reaching a query builder (§84).
        $this->actingFresh($this->tunde)
            ->get(route('drilldown.measure', ['kpiDefinition' => $this->kpi(), 'scope' => '1 OR 1=1']))
            ->assertOk()
            ->assertSee('Hydrotest procedure');
    }

    #[Test]
    public function another_companys_measure_is_not_found(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');

        $theirDirector = $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Director', [
                'document.view' => 'organization',
                'kpi.view' => 'organization',
                'kpi.manage' => 'organization',
                'dashboard.view' => 'organization',
                'dashboard.view_executive' => 'organization',
            ]),
        ]);

        $theirs = $this->withinOrganization($other, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
        ], $theirDirector));

        // §75. Tunde holds every dashboard, KPI and document permission there is — inside his own
        // company, which is the only place any of them mean anything.
        $this->actingFresh($this->tunde)->get(route('drilldown.measure', $theirs))->assertNotFound();
    }

    // ================================================================== the link itself

    #[Test]
    public function a_scoped_tile_offers_the_list_underneath_it_and_a_personal_tile_does_not(): void
    {
        $this->threeProcedures();

        $kpi = $this->kpi();

        $this->tile(['board' => 'executive', 'kpi_definition_id' => $kpi->getKey()]);
        $this->tile(['board' => 'personal', 'kpi_definition_id' => $kpi->getKey()]);

        $this->actingFresh($this->tunde)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('See the records')
            ->assertSee(route('drilldown.measure', $kpi));

        // A personal tile counts what is in front of one person already. There is no wider list
        // underneath "your open tasks", so the tile offers none rather than offering one that
        // would be narrowed straight back to the same handful.
        $this->actingFresh($this->tunde)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('drilldown.measure', $kpi));
    }

    // ================================================================== fixtures

    private function threeProcedures(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function tile(array $attributes = []): void
    {
        $this->withinOrganization($this->org, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'executive',
            'kind' => 'kpi_tile',
            'refresh' => 'live',
            'width' => 'third',
            ...$attributes,
        ], $this->tunde));
    }

    /**
     * The company-wide count of procedures, made once.
     *
     * Memoised because a second definition carrying a name already in use is a DomainException
     * rather than a second definition — the numbering rule that keeps a board's headings
     * unambiguous also makes a naive fixture throw on its second call.
     */
    private function kpi(): KpiDefinition
    {
        return $this->measures['organization'] ??= $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
        ], $this->tunde));
    }

    /** The same count, asked one department at a time. */
    private function byDepartment(): KpiDefinition
    {
        return $this->measures['department'] ??= $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Procedures by department',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'department',
            'period' => 'all_time',
        ], $this->tunde));
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function documentIn(OrgUnit $unit, string $title, array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $unit->getKey(),
            ...$attributes,
        ]);
    }
}
