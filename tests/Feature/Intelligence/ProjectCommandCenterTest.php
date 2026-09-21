<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectDepartment;
use App\Models\User;
use App\Services\DashboardWidgetService;
use App\Services\KpiDefinitionService;
use App\Services\ProjectTeamService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The middle of the drill-down path (§89, docs/11 §4): project → department → the registers.
 *
 * A PROJECT PAGE IS WHERE A DASHBOARD STOPS BEING A POSTER. docs/11 §4 draws the path as company,
 * then project, then department, then the records themselves, and the two rungs built here are the
 * ones that were missing: a project carrying its own figures, and one department's slice of one
 * project. Both are assembled from the same engine the boards use, so a company that arranges a
 * project tile once gets it on every project rather than on a page somebody remembered to wire.
 *
 * AND BOTH RUNGS RE-ASK THE QUESTION. Being allowed to open a project is not being handed every
 * record attached to it: each register on the slice is counted through the reader's own
 * `visibleTo`, and a register they hold no permission for is absent rather than shown empty — the
 * heading itself is a disclosure (§61). A department belonging to another project is a 404 before
 * any of that runs, because the route binds it as a child (§84, §94).
 *
 * THE TIMELINES ARE PART OF THE SAME CLAIM (§60). An activity row is a readable sentence naming a
 * record, so an unfiltered timeline hands out the contents of registers the reader was refused,
 * one description at a time. Both the landing page and the project page now pass their rows
 * through ActivityVisibility, which is what the last two tests here are about.
 */
final class ProjectCommandCenterTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $tunde;

    private User $musa;

    private User $ngozi;

    private Project $bridge;

    private Project $jetty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'project.view' => 'organization',
            'project.manage' => 'organization',
            'project.create' => 'organization',
            'project_member.view' => 'organization',
            'task.view' => 'organization',
            'task.create' => 'organization',
            'document.view' => 'organization',
            'document.create' => 'organization',
            'org_unit.view' => 'organization',
            'employee.view' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'dashboard.view' => 'organization',
            'dashboard.view_executive' => 'organization',
            'dashboard.manage' => 'organization',
        ]);

        // A reader who may open a project and read its documents, and who holds nothing at all on
        // the task register. The slice must not tell him how many tasks there are.
        $noTasks = $this->makeRole($this->org, 'Project Accountant', [
            'project.view' => 'organization',
            'project_member.view' => 'organization',
            'document.view' => 'organization',
            'org_unit.view' => 'organization',
            'dashboard.view' => 'organization',
        ]);

        $departmental = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'org_unit.view' => 'department',
            'dashboard.view' => 'organization',
        ]);

        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->musa = $this->makePerson($this->org, 'Musa Garba', null, [$noTasks]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$departmental]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);

        $this->bridge = $this->makeProject($this->org, $this->tunde, 'Kano Bridge Rehabilitation');
        $this->jetty = $this->makeProject($this->org, $this->tunde, 'Onne Jetty Extension');
    }

    // ================================================================== the project's own board

    #[Test]
    public function a_project_page_carries_the_figures_arranged_for_every_project(): void
    {
        $this->taskOn($this->bridge, $this->civil, 'Pour pier 3');
        $this->taskOn($this->bridge, $this->civil, 'Survey abutment');
        $this->taskOn($this->bridge, $this->piping, 'Reroute service main');
        $this->taskOn($this->jetty, $this->civil, 'Mobilise barge');

        $this->tile();

        // One arrangement, every project. The tile was never told which project it is about — the
        // page supplies that, the same way a department board supplies its unit.
        $this->actingFresh($this->tunde)->get(route('projects.show', $this->bridge))
            ->assertOk()
            ->assertSee('Open work')
            ->assertSee('>3<', false);

        $this->actingFresh($this->tunde)->get(route('projects.show', $this->jetty))
            ->assertOk()
            ->assertSee('>1<', false);
    }

    // ================================================================== one department's slice

    #[Test]
    public function a_department_slice_counts_only_that_department_on_that_project(): void
    {
        $this->taskOn($this->bridge, $this->civil, 'Pour pier 3');
        $this->taskOn($this->bridge, $this->civil, 'Survey abutment');
        $this->taskOn($this->bridge, $this->piping, 'Reroute service main');
        $this->taskOn($this->jetty, $this->civil, 'Mobilise barge');

        $assignment = $this->assign($this->bridge, $this->civil);

        // Two axes narrowed at once, which is the whole point of the rung: Piping's work on this
        // project and Civil's work on the next one are both outside the slice.
        $this->actingFresh($this->tunde)->get(route('projects.departments.show', [$this->bridge, $assignment]))
            ->assertOk()
            ->assertSee('Civil')
            ->assertSee('Pour pier 3')
            ->assertSee('Survey abutment')
            ->assertDontSee('Reroute service main')
            ->assertDontSee('Mobilise barge');
    }

    #[Test]
    public function a_register_the_reader_cannot_read_is_absent_from_the_slice(): void
    {
        $this->taskOn($this->bridge, $this->civil, 'Pour pier 3');

        $this->makeDocument($this->org, $this->tunde, 'Pier reinforcement drawing register', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
            'project_id' => $this->bridge->getKey(),
        ]);

        $assignment = $this->assign($this->bridge, $this->civil);

        // §61. Musa holds `document.view` and no task permission, so he is given the document
        // register and not told that a task register exists, let alone how full it is.
        $this->actingFresh($this->musa)->get(route('projects.departments.show', [$this->bridge, $assignment]))
            ->assertOk()
            ->assertSee('Pier reinforcement drawing register')
            ->assertDontSee('Pour pier 3');
    }

    #[Test]
    public function a_department_committed_to_another_project_is_not_found(): void
    {
        $elsewhere = $this->assign($this->jetty, $this->civil);

        // §84. The id is real, the department is real, the reader holds `project.view`
        // organization-wide — and the assignment is not this project's, which the scoped binding
        // settles before the controller runs.
        $this->actingFresh($this->tunde)->get(route('projects.departments.show', [$this->bridge, $elsewhere]))
            ->assertNotFound();
    }

    #[Test]
    public function a_project_nobody_may_open_does_not_open_at_its_department(): void
    {
        $assignment = $this->assign($this->bridge, $this->civil);

        // The slice is a project page. Somebody with no project permission is refused here for the
        // same reason and by the same policy call as on the project's own page — the drill-down
        // adds a rung to the path, not a way around it.
        $this->actingFresh($this->ngozi)->get(route('projects.departments.show', [$this->bridge, $assignment]))
            ->assertForbidden();
    }

    // ================================================================== the timelines (§60)

    #[Test]
    public function the_landing_page_timeline_shows_only_activity_about_records_the_reader_may_open(): void
    {
        $this->makeDocument($this->org, $this->tunde, 'Lifting operations procedure', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
        ]);

        $this->makeDocument($this->org, $this->tunde, 'Hydrotest procedure', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->piping->getKey(),
        ]);

        // Ngozi reads Civil. An activity row is a sentence naming the record it is about, so an
        // unfiltered timeline would have handed her the title of a document the register itself
        // refuses her — which is the §60 hole this closes.
        $this->actingFresh($this->ngozi)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertDontSee('Hydrotest procedure');
    }

    #[Test]
    public function a_project_timeline_is_filtered_the_same_way(): void
    {
        $this->taskOn($this->bridge, $this->civil, 'Pour pier 3');

        $this->makeDocument($this->org, $this->tunde, 'Pier reinforcement drawing register', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
            'project_id' => $this->bridge->getKey(),
        ]);

        // The project timeline is every module's activity in one list, which is exactly what made
        // it worth filtering: Musa may open the project and read its documents, and the task he
        // was never granted stays off his page even as a sentence about it.
        $this->actingFresh($this->musa)->get(route('projects.show', $this->bridge))
            ->assertOk()
            ->assertSee('Pier reinforcement drawing register')
            ->assertDontSee('Pour pier 3');
    }

    // ================================================================== fixtures

    private function taskOn(Project $project, OrgUnit $unit, string $title): void
    {
        $this->makeTask($this->org, $this->tunde, $title, [
            'project_id' => $project->getKey(),
            'org_unit_id' => $unit->getKey(),
        ]);
    }

    private function assign(Project $project, OrgUnit $unit): ProjectDepartment
    {
        return $this->withinOrganization($this->org, fn () => app(ProjectTeamService::class)->attachDepartment(
            $project,
            ['org_unit_id' => $unit->getKey()],
            $this->tunde,
        ));
    }

    /** One project tile, arranged once for every project in the company. */
    private function tile(): void
    {
        $kpi = $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Open work',
            'entity_type' => 'task',
            'aggregate' => 'count',
            'scope' => 'project',
            'period' => 'all_time',
        ], $this->tunde));

        $this->withinOrganization($this->org, fn () => app(DashboardWidgetService::class)->create([
            'board' => 'project',
            'kind' => 'kpi_tile',
            'refresh' => 'live',
            'width' => 'third',
            'kpi_definition_id' => $kpi->getKey(),
        ], $this->tunde));
    }
}
