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
 * The command centers (§52, §53) — the pages the Part 1 engine finally has to sit on.
 *
 * THE BOARD IS A PAGE PERMISSION; THE FIGURES ARE NOT. Opening the executive board and being able
 * to read what is on it are two separate questions with two separate answers, and keeping them
 * apart is what makes ONE arrangement serve a whole company. A managing director and a department
 * head open the same URL, get the same tiles where they share a register, and see different
 * numbers under the same headings — each correct for the person reading it.
 *
 * WHICH DEPARTMENT A BOARD IS ABOUT IS NEVER DECIDED BY THE URL. The id in the address bar is a
 * request. What settles it is whether that unit survives `visibleTo`, re-asked on every load, and
 * a department head who edits the id to a sister's gets the same 404 they would get for a unit in
 * another company — deliberately the same answer, because distinguishing "exists but not yours"
 * from "does not exist" is how an id becomes an oracle (§75, §84).
 *
 *   Engineering (division)   Emeka   division reach; executive + department boards
 *   Civil (department)       Ngozi   Civil only; department board only
 *   Piping (department)      Sade    Piping only; department board only
 *   (company)                Tunde   everything, and arranges the boards
 *   (company)                Kemi    executive board only, no department board
 */
final class DashboardBoardTest extends TestCase
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

    private User $emeka;

    private User $ngozi;

    private User $sade;

    private User $tunde;

    private User $kemi;

    /** @var array<string,KpiDefinition> */
    private array $measures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        // A section INSIDE Civil. This is what makes "a bare visit lands you on your department"
        // a real test rather than a restatement — somebody sitting here has no board of their own.
        $this->survey = $this->makeUnit($this->org, 'Survey', OrgUnitType::Section, $this->civil);

        $divisionHead = $this->makeRole($this->org, 'Division Head', [
            'document.view' => 'division',
            'document.view_confidential' => 'division',
            'org_unit.view' => 'division',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.view_department' => 'organization',
        ]);

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

        // The executive board and nothing below it. A real arrangement: somebody on the board of
        // directors has no business opening the board for one department.
        $executiveOnly = $this->makeRole($this->org, 'Non-executive Director', [
            'document.view' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
        ]);

        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->engineering, [$divisionHead]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->survey, [$departmental]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$departmental]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->kemi = $this->makePerson($this->org, 'Kemi Alabi', null, [$executiveOnly]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== who may open one

    #[Test]
    public function each_board_is_behind_its_own_permission(): void
    {
        // Two different decisions about two different people, so two different permissions. Kemi
        // may look at the company and not at one department; Ngozi the reverse.
        $this->actingFresh($this->kemi)->get(route('boards.executive'))->assertOk();
        $this->actingFresh($this->kemi)->get(route('boards.department'))->assertForbidden();

        $this->actingFresh($this->ngozi)->get(route('boards.executive'))->assertForbidden();
        $this->actingFresh($this->ngozi)->get(route('boards.department'))->assertRedirect();
    }

    #[Test]
    public function the_sidebar_offers_a_board_only_to_somebody_who_may_open_it(): void
    {
        // §96, and the half of it that matters: the menu is built from the same permissions the
        // routes carry, so nobody is shown a link into a 403.
        $this->actingFresh($this->kemi)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('boards.executive'))
            ->assertDontSee(route('boards.department'));

        $this->actingFresh($this->ngozi)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('boards.executive'));
    }

    // ================================================================== one board, many readers

    #[Test]
    public function one_arrangement_gives_each_reader_their_own_figures(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile(['board' => 'executive', 'kpi_definition_id' => $this->kpi()->getKey()]);

        // The company's own director sees three. The division head sees three because the division
        // holds both departments. Neither number was counted for the other.
        $this->actingFresh($this->tunde)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('Procedures on file')
            ->assertSee('>3<', false);

        $this->actingFresh($this->emeka)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('>3<', false);
    }

    #[Test]
    public function a_tile_whose_register_the_reader_cannot_read_is_absent_from_the_page(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile([
            'board' => 'executive',
            'kpi_definition_id' => $this->kpi(['entity_type' => 'project', 'name' => 'Projects running'])->getKey(),
            'title' => 'Projects running',
        ]);

        // Kemi holds `dashboard.view_executive` and no project permission. §6: the heading is not
        // in the page source at all, so it survives a print view and a screenshot.
        $this->actingFresh($this->kemi)->get(route('boards.executive'))
            ->assertOk()
            ->assertDontSee('Projects running');

        $this->actingFresh($this->tunde)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('Projects running');
    }

    #[Test]
    public function an_unarranged_board_explains_itself_instead_of_showing_a_blank_page(): void
    {
        // §73: the first person to open a board is usually the person about to arrange it, and the
        // page they land on should say what the next step is.
        $this->actingFresh($this->tunde)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('Nothing on this board yet')
            ->assertSee(route('configuration.widgets.create', ['board' => 'executive']));

        // Somebody who may not arrange it is told what the board is for and offered no button.
        $this->actingFresh($this->kemi)->get(route('boards.executive'))
            ->assertOk()
            ->assertSee('Nothing on this board yet')
            ->assertDontSee(route('configuration.widgets.create', ['board' => 'executive']));
    }

    // ================================================================== department boards

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

        $this->actingFresh($this->tunde)->get(route('boards.department', $this->civil))
            ->assertOk()
            ->assertSee('>2<', false);

        $this->actingFresh($this->tunde)->get(route('boards.department', $this->piping))
            ->assertOk()
            ->assertSee('>1<', false);
    }

    #[Test]
    public function a_department_head_cannot_reach_a_sisters_board_by_editing_the_url(): void
    {
        $this->tile([
            'board' => 'department',
            'kpi_definition_id' => $this->kpi(['scope' => 'department'])->getKey(),
        ]);

        // §84. The id is sequential, the route model binding finds the row, and Ngozi holds
        // `dashboard.view_department` organization-wide — none of which is entitlement to Piping.
        $this->actingFresh($this->ngozi)->get(route('boards.department', $this->piping))->assertNotFound();

        // And her own department opens, so the 404 is about reach rather than about the route.
        $this->actingFresh($this->ngozi)->get(route('boards.department', $this->civil))->assertOk();
    }

    #[Test]
    public function another_companys_department_is_not_found_rather_than_forbidden(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');
        $theirs = $this->makeUnit($other, 'Fabrication', OrgUnitType::Department);

        // §75. Tunde holds every dashboard and org-unit permission there is, organization-wide.
        $this->actingFresh($this->tunde)->get(route('boards.department', $theirs))->assertNotFound();
    }

    #[Test]
    public function a_bare_visit_lands_somebody_on_their_own_department(): void
    {
        // Ngozi sits in Survey, a SECTION inside Civil, which has no board of its own. The walk up
        // the chart is what makes the sidebar link work for most of a company.
        $this->actingFresh($this->ngozi)->get(route('boards.department'))
            ->assertRedirect(route('boards.department', $this->civil));

        $this->actingFresh($this->sade)->get(route('boards.department'))
            ->assertRedirect(route('boards.department', $this->piping));
    }

    #[Test]
    public function somebody_with_no_department_at_all_is_told_so_rather_than_shown_an_empty_board(): void
    {
        // A person with the permission, no unit of their own, and no unit they can reach. An empty
        // grid would read as "your department has nothing in it", which is a different and wrong
        // statement.
        $stranded = $this->makePerson($this->org, 'Ifeoma Nwosu', null, [
            $this->makeRole($this->org, 'Board Watcher', [
                'dashboard.view' => 'organization',
                'dashboard.view_department' => 'organization',
            ]),
        ]);

        $this->actingFresh($stranded)->get(route('boards.department'))
            ->assertOk()
            ->assertSee('No department board to show you');
    }

    #[Test]
    public function the_picker_offers_only_departments_this_person_may_open(): void
    {
        $this->actingFresh($this->emeka)->get(route('boards.department', $this->civil))
            ->assertOk()
            ->assertSee('Civil')
            ->assertSee('Piping');

        // §61 applied to a select box: a list naming every department would tell Ngozi the shape of
        // an organization the org chart never showed her.
        $this->actingFresh($this->ngozi)->get(route('boards.department', $this->civil))
            ->assertOk()
            ->assertDontSee('Piping');
    }

    // ================================================================== the landing page

    #[Test]
    public function the_personal_board_appears_on_the_page_everybody_already_lands_on(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $this->tile([
            'board' => 'personal',
            'kpi_definition_id' => $this->kpi()->getKey(),
            'title' => 'Procedures you can open',
        ]);

        // §90: not a fourth page to visit. What a company wants everybody to notice goes where
        // everybody already is.
        $this->actingFresh($this->emeka)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Procedures you can open');
    }

    #[Test]
    public function the_landing_page_still_works_for_a_company_that_has_arranged_nothing(): void
    {
        // The structural cards stayed for exactly this reason. A new company gets a landing page
        // with something on it rather than a greeting and white space.
        $this->actingFresh($this->emeka)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your record');
    }

    #[Test]
    public function a_personal_tile_counts_for_the_person_looking_at_it(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $this->tile([
            'board' => 'personal',
            'kpi_definition_id' => $this->kpi()->getKey(),
            'title' => 'Procedures you can open',
        ]);

        $this->actingFresh($this->tunde)->get(route('dashboard'))->assertOk()->assertSee('>2<', false);
        $this->actingFresh($this->ngozi)->get(route('dashboard'))->assertOk()->assertSee('>1<', false);
    }

    // ================================================================== plumbing

    /** @param  array<string,mixed>  $attributes */
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

    /** @param  array<string,mixed>  $attributes */
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
    private function documentIn(OrgUnit $unit, string $title, array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $unit->getKey(),
            ...$attributes,
        ]);
    }
}
