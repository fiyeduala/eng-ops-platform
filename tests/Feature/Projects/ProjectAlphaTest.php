<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Engines\Access\ActorProfile;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\ProjectTeamService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The §8 Project Alpha scenario, as far as Phase 2 can carry it.
 *
 * Setup, from docs/04 §10: Project Alpha involves Engineering, HSE, Procurement and Finance. John
 * and Mary (Engineering), David (HSE), Sarah (Procurement) and Michael (Finance) are on the team.
 * None of them holds a grant that reaches the project through the org chart — Alpha is owned by
 * Project Delivery, a unit none of their departments contains — so every one of them reaches it
 * through the project axis and nothing else. That is the point of the scenario: five people from
 * five departments, one shared project, no department-wide leak in either direction.
 *
 * The document, task, HSE and procurement halves of §8 attach to this file as those modules land.
 * What is asserted here is the layer they all rest on: who reaches the project, who does not, and
 * what happens at each of the edges — expiry, confidentiality, the cascade's department bounds,
 * IDOR through a nested route, and the tenant boundary.
 */
final class ProjectAlphaTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $structural;

    private OrgUnit $hse;

    private OrgUnit $procurement;

    private OrgUnit $finance;

    private OrgUnit $delivery;

    private User $priya;      // project manager, project-scoped authority

    private User $john;       // Engineering

    private User $mary;       // Engineering

    private User $david;      // HSE

    private User $sarah;      // Procurement

    private User $michael;    // Finance

    private User $nadia;      // Engineering, NOT on the team

    private Role $teamRole;

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->structural = $this->makeUnit($this->org, 'Structural', OrgUnitType::Department, $engineering);
        $this->hse = $this->makeUnit($this->org, 'HSE', OrgUnitType::Department);
        $this->procurement = $this->makeUnit($this->org, 'Procurement', OrgUnitType::Department);
        $this->finance = $this->makeUnit($this->org, 'Finance', OrgUnitType::Department);
        $this->delivery = $this->makeUnit($this->org, 'Project Delivery', OrgUnitType::Department);

        // A project manager's authority is the project, not a slice of the org chart (docs/05 §6).
        $managerRole = $this->makeRole($this->org, 'Project Manager', [
            'project.view' => 'project',
            'project.edit' => 'project',
            'project.manage' => 'project',
            'project.archive' => 'project',
            'project.view_financials' => 'project',
            'project_member.view' => 'project',
            'project_member.assign' => 'project',
            'project_member.manage' => 'project',
            'project_member.remove' => 'project',
            'user.view' => 'organization',
        ]);

        // What every team member holds: the project axis and nothing wider.
        $this->teamRole = $teamRole = $this->makeRole($this->org, 'Team Member', [
            'project.view' => 'project',
            'project_member.view' => 'project',
        ]);

        $this->priya = $this->makePerson($this->org, 'Priya Raman', $this->delivery, [$managerRole]);
        $this->john = $this->makePerson($this->org, 'John Adeyemi', $this->structural, [$teamRole]);
        $this->mary = $this->makePerson($this->org, 'Mary Okafor', $this->structural, [$teamRole]);
        $this->david = $this->makePerson($this->org, 'David Nwosu', $this->hse, [$teamRole]);
        $this->sarah = $this->makePerson($this->org, 'Sarah Bello', $this->procurement, [$teamRole]);

        // Michael reaches Finance-owned work through the org chart as well as the project axis —
        // which is what makes the confidentiality assertion below a real distinction rather than a
        // difference in permissions.
        $financeRole = $this->makeRole($this->org, 'Finance Officer', [
            'project.view' => ['project', 'department'],
            'project_member.view' => 'project',
        ]);

        $this->michael = $this->makePerson($this->org, 'Michael Eze', $this->finance, [$financeRole]);

        // An engineer with department-scoped sight of projects and no membership. She is the
        // control: if she can see Alpha, membership is leaking into the org axis.
        $departmentRole = $this->makeRole($this->org, 'Engineer', [
            'project.view' => 'department',
            'project_member.view' => 'department',
        ]);

        $this->nadia = $this->makePerson($this->org, 'Nadia Farouk', $this->structural, [$departmentRole]);

        $this->alpha = $this->makeProject($this->org, $this->priya, 'Project Alpha', [
            'org_unit_id' => $this->delivery->id,
            'manager_id' => $this->priya->id,
            'contract_value' => 4_500_000,
            'currency' => 'USD',
        ]);

        foreach ([$this->john, $this->mary, $this->david, $this->sarah, $this->michael] as $person) {
            $this->addToTeam($this->alpha, $person);
        }
    }

    // ------------------------------------------------------------------ the scenario

    #[Test]
    public function all_five_members_reach_project_alpha_through_the_project_axis_alone(): void
    {
        foreach ($this->teamOfFive() as $label => $person) {
            $this->actingFresh($person)
                ->get(route('projects.show', $this->alpha))
                ->assertOk()
                ->assertSee('Project Alpha');

            $this->actingFresh($person)
                ->get(route('projects.index'))
                ->assertOk()
                ->assertSee('Project Alpha', false);

            // The list and the record page must agree — a page you can open but that never appears
            // in your list is the bug §61 is about, in the other direction.
            $this->assertTrue(
                $this->visibleIdsFor($person)->contains($this->alpha->id),
                $label.' can open Project Alpha but it is missing from the compiled list.',
            );
        }
    }

    #[Test]
    public function an_engineer_who_is_not_on_the_team_cannot_reach_it(): void
    {
        // Nadia sits in the same department as John and Mary and holds project.view at department
        // scope. Alpha is owned by Project Delivery, so nothing on the org axis reaches it.
        $this->actingFresh($this->nadia)
            ->get(route('projects.show', $this->alpha))
            ->assertForbidden();

        $this->actingFresh($this->nadia)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('Project Alpha', false);

        $this->assertFalse($this->visibleIdsFor($this->nadia)->contains($this->alpha->id));
    }

    #[Test]
    public function membership_that_has_expired_grants_nothing(): void
    {
        $this->withinOrganization($this->org, function () {
            ProjectMember::query()
                ->where('project_id', $this->alpha->id)
                ->where('user_id', $this->john->id)
                ->update(['ends_on' => now()->subDay()->toDateString()]);
        });

        app(ActorProfile::class)->flush();

        $this->actingFresh($this->john)
            ->get(route('projects.show', $this->alpha))
            ->assertForbidden();

        // Mary's row is untouched, so this is expiry rather than the fixture falling over.
        $this->actingFresh($this->mary)
            ->get(route('projects.show', $this->alpha))
            ->assertOk();
    }

    #[Test]
    public function membership_does_not_lift_confidentiality(): void
    {
        // A restricted Finance project, with Sarah from Procurement on the team. docs/05 §4: above
        // `internal`, the record has to be reachable through the owning unit or an explicit grant,
        // and being on the team is not one of those.
        $bravo = $this->makeProject($this->org, $this->michael, 'Cost Recovery Review', [
            'org_unit_id' => $this->finance->id,
            'manager_id' => $this->michael->id,
            'confidentiality' => Confidentiality::Restricted,
        ]);

        $this->addToTeam($bravo, $this->sarah);

        $this->actingFresh($this->sarah)
            ->get(route('projects.show', $bravo))
            ->assertForbidden();

        $this->assertFalse($this->visibleIdsFor($this->sarah)->contains($bravo->id));

        // Michael reaches it because Finance owns it, not because he is on the team.
        $this->actingFresh($this->michael)
            ->get(route('projects.show', $bravo))
            ->assertOk();
    }

    #[Test]
    public function a_department_head_may_commit_their_own_people_and_nobody_elses(): void
    {
        $headRole = $this->makeRole($this->org, 'HSE Manager', [
            'project.view' => 'project',
            'project_member.view' => 'project',
            'project_member.assign' => 'department',
            'user.view' => 'department',
        ]);

        $kwame = $this->makePerson($this->org, 'Kwame Boateng', $this->hse, [$headRole]);
        $tunde = $this->makePerson($this->org, 'Tunde Salami', $this->hse);

        $this->addToTeam($this->alpha, $kwame);

        // His own department: the cascade working as designed.
        $this->actingFresh($kwame)
            ->post(route('projects.team.store', $this->alpha), ['user_id' => $tunde->id])
            ->assertRedirect(route('projects.team', $this->alpha));

        $this->assertTrue($this->onTeam($this->alpha, $tunde));
        $this->assertTrue($this->memberRow($this->alpha, $tunde)->isCurrent());

        // Somebody else's: refused, and refused server-side rather than by not being offered.
        $this->actingFresh($kwame)
            ->post(route('projects.team.store', $this->alpha), ['user_id' => $this->nadia->id])
            ->assertForbidden();

        $this->assertFalse($this->onTeam($this->alpha, $this->nadia));
    }

    #[Test]
    public function the_project_manager_may_commit_someone_from_a_department_they_have_no_authority_over(): void
    {
        // Priya sits in Project Delivery and has no org-chart authority over Procurement at all.
        // Her `project_member.assign` is project-scoped, and she is on this project, so the bound
        // on her staffing is the project rather than a subtree (docs/05 §6).
        $rita = $this->makePerson($this->org, 'Rita Mensah', $this->procurement, [$this->teamRole]);

        $this->actingFresh($rita)
            ->get(route('projects.show', $this->alpha))
            ->assertForbidden();

        $this->actingFresh($this->priya)
            ->post(route('projects.team.store', $this->alpha), ['user_id' => $rita->id])
            ->assertRedirect(route('projects.team', $this->alpha));

        $this->assertTrue($this->onTeam($this->alpha, $rita));

        // And it is a row that actually grants. An assignment saved as inactive, or dated to start
        // later, would list on the roster and read as success while reaching nothing.
        $this->assertTrue(
            $this->memberRow($this->alpha, $rita)->isCurrent(),
            'the membership created through the form does not grant anything today',
        );

        // The row is what changed the answer. Nothing about Rita's roles was touched.
        app(ActorProfile::class)->flush();

        $this->actingFresh($rita)
            ->get(route('projects.show', $this->alpha))
            ->assertOk();
    }

    #[Test]
    public function a_membership_row_widens_a_project_scoped_grant_and_manufactures_none(): void
    {
        // The half of the two-axis rule that is easy to get wrong in the generous direction.
        // Nadia's `project.view` is department-scoped: it reaches projects owned by her subtree and
        // says nothing about project membership. Putting her on the team is a real, recorded change
        // — and it still does not let her open the project, because she holds no project-scoped
        // grant for the membership to widen. Reach is a grant AND a scope, never a row alone.
        $this->actingFresh($this->priya)
            ->post(route('projects.team.store', $this->alpha), ['user_id' => $this->nadia->id])
            ->assertRedirect(route('projects.team', $this->alpha));

        $this->assertTrue($this->onTeam($this->alpha, $this->nadia));

        app(ActorProfile::class)->flush();

        $this->actingFresh($this->nadia)
            ->get(route('projects.show', $this->alpha))
            ->assertForbidden();

        $this->assertFalse($this->visibleIdsFor($this->nadia)->contains($this->alpha->id));
    }

    #[Test]
    public function a_membership_row_cannot_be_reached_through_another_projects_url(): void
    {
        // §94's IDOR case, on a nested route. The member id is real and the actor may administer
        // both projects; only the pairing is wrong, and scoped bindings make that a 404 at the
        // router rather than a policy question asked about the wrong record.
        $bravo = $this->makeProject($this->org, $this->priya, 'Harbour Survey', [
            'org_unit_id' => $this->delivery->id,
            'manager_id' => $this->priya->id,
        ]);

        $stranger = $this->addToTeam($bravo, $this->david);

        $this->actingFresh($this->priya)
            ->delete(route('projects.team.destroy', [$this->alpha, $stranger]))
            ->assertNotFound();

        $this->assertTrue($this->onTeam($bravo, $this->david));
    }

    #[Test]
    public function another_organization_gets_a_404_rather_than_a_refusal(): void
    {
        $other = $this->makeOrganization('Kestrel Facilities');
        $unit = $this->makeUnit($other, 'Operations', OrgUnitType::Department);

        $role = $this->makeRole($other, 'Administrator', [
            'project.view' => 'organization',
            'project_member.view' => 'organization',
        ]);

        $outsider = $this->makePerson($other, 'Rhona Calder', $unit, [$role]);

        // 404, not 403: the tenant scope makes the row not exist, so the response does not confirm
        // that a project with this id is there to be asked about (§75).
        $this->actingFresh($outsider)
            ->get(route('projects.show', $this->alpha))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ money and status

    #[Test]
    public function the_contract_value_needs_its_own_permission(): void
    {
        $this->actingFresh($this->priya)
            ->get(route('projects.show', $this->alpha))
            ->assertOk()
            ->assertSee('4,500,000.00');

        $this->actingFresh($this->john)
            ->get(route('projects.show', $this->alpha))
            ->assertOk()
            ->assertDontSee('4,500,000.00')
            ->assertSee('project.view_financials');
    }

    #[Test]
    public function a_team_member_cannot_edit_the_project_they_can_see(): void
    {
        // Reach is not authority (§14). John sees Alpha; he does not get to change it.
        $this->actingFresh($this->john)
            ->get(route('projects.edit', $this->alpha))
            ->assertForbidden();

        $this->actingFresh($this->john)
            ->put(route('projects.update', $this->alpha), [
                'name' => 'Renamed by somebody who should not be able to',
                'confidentiality' => Confidentiality::Internal->value,
                'priority' => 'normal',
            ])
            ->assertForbidden();

        $this->assertSame('Project Alpha', $this->alpha->fresh()->name);
    }

    #[Test]
    public function the_status_field_is_not_a_text_box(): void
    {
        $before = (int) $this->alpha->status_id;

        // A status id belonging to another kind of record — a real row, in this tenant, that a
        // project may simply never be in. And a real move that exists in the vocabulary but not
        // from where this project currently stands: draft goes to planning, not straight to work.
        $foreign = $this->statusNamed('task', 'in_progress');
        $unreachable = $this->statusNamed('project', 'completed');

        foreach ([$foreign, $unreachable] as $status) {
            $this->actingFresh($this->priya)
                ->post(route('projects.status', $this->alpha), ['status_id' => $status->id])
                ->assertSessionHasErrors('status_id');

            $this->assertSame($before, (int) $this->alpha->fresh()->status_id);
        }
    }

    #[Test]
    public function a_move_that_demands_a_reason_is_refused_without_one(): void
    {
        // Cancelling is reachable from anywhere, and the catalogue marks it as requiring a comment.
        // The textarea on the page is bound to the same flag, but the flag is enforced here.
        $cancelled = $this->statusNamed('project', 'cancelled');

        $this->actingFresh($this->priya)
            ->post(route('projects.status', $this->alpha), ['status_id' => $cancelled->id])
            ->assertSessionHasErrors('status_id');

        $this->assertNotSame((int) $cancelled->id, (int) $this->alpha->fresh()->status_id);
    }

    #[Test]
    public function a_permitted_move_is_written_and_shows_up_on_the_timeline(): void
    {
        $planning = $this->statusNamed('project', 'planning');

        $this->actingFresh($this->priya)
            ->post(route('projects.status', $this->alpha), [
                'status_id' => $planning->id,
                'comment' => 'Client instruction to proceed received.',
            ])
            ->assertRedirect(route('projects.show', $this->alpha));

        $this->assertSame((int) $planning->id, (int) $this->alpha->fresh()->status_id);

        // The reason is not swallowed: it is on the record where the next person will look for it.
        $this->actingFresh($this->priya)
            ->get(route('projects.show', $this->alpha))
            ->assertOk()
            ->assertSee('Client instruction to proceed received.');
    }

    #[Test]
    public function a_team_member_cannot_move_the_status_they_can_read(): void
    {
        $planning = $this->statusNamed('project', 'planning');

        $this->actingFresh($this->john)
            ->post(route('projects.status', $this->alpha), ['status_id' => $planning->id])
            ->assertForbidden();

        $this->assertNotSame((int) $planning->id, (int) $this->alpha->fresh()->status_id);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,User> */
    private function teamOfFive(): array
    {
        return [
            'John' => $this->john,
            'Mary' => $this->mary,
            'David' => $this->david,
            'Sarah' => $this->sarah,
            'Michael' => $this->michael,
        ];
    }

    private function addToTeam(Project $project, User $person): ProjectMember
    {
        $member = $this->withinOrganization($this->org, fn () => app(ProjectTeamService::class)
            ->addMember($project, ['user_id' => $person->id], $this->priya));

        app(ActorProfile::class)->flush();

        return $member;
    }

    private function memberRow(Project $project, User $person): ProjectMember
    {
        return $this->withinOrganization($this->org, fn () => ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $person->id)
            ->firstOrFail());
    }

    private function onTeam(Project $project, User $person): bool
    {
        return $this->withinOrganization($this->org, fn () => ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $person->id)
            ->exists());
    }

    private function statusNamed(string $entityType, string $key): Status
    {
        return $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity($entityType)
            ->where('key', $key)
            ->firstOrFail());
    }

    /** The ids the access engine compiles for this person — the list behind every index page. */
    private function visibleIdsFor(User $person): Collection
    {
        return $this->withinOrganization(
            $this->org,
            fn () => Project::visibleTo($person, 'project.view')->pluck('id'),
        );
    }
}
