<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Engines\Access\ActorProfile;
use App\Models\Follower;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\ProjectTeamService;
use App\Services\TaskChecklistService;
use App\Services\TaskDependencyService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Tasks: the first module where both visibility axes are load-bearing at once (§16, §88, §90).
 *
 * The fixture is one company, two departments, and one project owned by neither of them:
 *
 *   Structural (dept)      Priya — project manager, project-scoped authority
 *   Structural (dept)      John  — engineer, `task.view:assigned` and nothing wider
 *   Structural (dept)      Lade  — lead engineer, `task.view:department` over Structural
 *   HSE (dept)             David — HSE officer, on the project team, `task.view:project`
 *   HSE (dept)             Tunde — HSE, on nothing
 *
 * That shape is what makes the assertions below distinguishable. John reaches a task ONLY because
 * it is on him; Lade reaches it only because Structural owns it; David only because he is on the
 * project. Break any one of the three axes and exactly one test fails, which is the property a
 * union of ORs otherwise loses.
 */
final class TaskAccessTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $structural;

    private OrgUnit $hse;

    private OrgUnit $delivery;

    private User $priya;      // project manager

    private User $john;       // engineer: assigned-scope only

    private User $lade;       // lead engineer: department scope over Structural

    private User $david;      // HSE, on the project team

    private User $tunde;      // HSE, on nothing

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->structural = $this->makeUnit($this->org, 'Structural', OrgUnitType::Department, $engineering);
        $this->hse = $this->makeUnit($this->org, 'HSE', OrgUnitType::Department);
        $this->delivery = $this->makeUnit($this->org, 'Project Delivery', OrgUnitType::Department);

        $managerRole = $this->makeRole($this->org, 'Project Manager', [
            'project.view' => 'project',
            'project_member.view' => 'project',
            'project_member.assign' => 'project',
            'task.view' => 'project',
            'task.create' => 'project',
            'task.edit' => 'project',
            'task.delete' => 'project',
            'task.manage' => 'project',
            'task.assign' => 'project',
            'task.reassign' => 'project',
            'task.complete' => 'project',
            'task.verify' => 'project',
            'user.view' => 'organization',
        ]);

        // The doer. `assigned` and nothing else: he sees the work that is on him, and may say it is
        // finished. He may not accept it — that is `task.verify`, and it is not here (§16).
        $engineerRole = $this->makeRole($this->org, 'Engineer', [
            'task.view' => 'assigned',
            'task.edit' => 'assigned',
            'task.complete' => 'assigned',
            'project.view' => 'project',
        ]);

        // The org axis, on its own: no membership of anything. `task.reassign` is here so the
        // refusal below is canCommit() doing its job — without it, taking work off a named person
        // would be refused for the duller reason that Lade cannot reassign anything at all.
        $leadRole = $this->makeRole($this->org, 'Lead Engineer', [
            'task.view' => 'department',
            'task.edit' => 'department',
            'task.assign' => 'department',
            'task.reassign' => 'department',
            'user.view' => 'department',
        ]);

        // The project axis, on its own.
        $teamRole = $this->makeRole($this->org, 'Team Member', [
            'task.view' => 'project',
            'project.view' => 'project',
        ]);

        $this->priya = $this->makePerson($this->org, 'Priya Raman', $this->delivery, [$managerRole]);
        $this->john = $this->makePerson($this->org, 'John Adeyemi', $this->structural, [$engineerRole]);
        $this->lade = $this->makePerson($this->org, 'Lade Bakare', $this->structural, [$leadRole]);
        $this->david = $this->makePerson($this->org, 'David Nwosu', $this->hse, [$teamRole]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Salami', $this->hse, [$teamRole]);

        $this->alpha = $this->makeProject($this->org, $this->priya, 'Project Alpha', [
            'org_unit_id' => $this->delivery->id,
            'manager_id' => $this->priya->id,
        ]);

        foreach ([$this->john, $this->david] as $person) {
            $this->addToTeam($this->alpha, $person);
        }
    }

    // ------------------------------------------------------------------ the two axes

    #[Test]
    public function three_people_reach_one_task_by_three_different_routes(): void
    {
        // Owned by Structural, filed against Alpha, assigned to John. Every one of the three axes
        // is live on this single row, which is the whole point of §90's "one system".
        $task = $this->task('Check the abutment reinforcement', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->structural->id,
            'assignee_id' => $this->john->id,
        ]);

        foreach (['John (assigned)' => $this->john,
            'Lade (department)' => $this->lade,
            'David (project)' => $this->david] as $label => $person) {
            $this->actingFresh($person)
                ->get(route('tasks.show', $task))
                ->assertOk()
                ->assertSee('Check the abutment reinforcement');

            $this->assertTrue(
                $this->visibleIdsFor($person)->contains($task->id),
                $label.' can open the task but it is missing from the compiled list.',
            );
        }
    }

    #[Test]
    public function each_axis_reaches_only_what_it_is_supposed_to(): void
    {
        // Structural work, on nobody, on no project. Only the org axis reaches it.
        $unitOnly = $this->task('Update the structural calculation sheet', [
            'org_unit_id' => $this->structural->id,
        ]);

        // Alpha work, owned by Project Delivery, on nobody. Only the project axis reaches it.
        $projectOnly = $this->task('Compile the site safety file', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->assertReach($this->lade, $unitOnly, true, 'Lade owns Structural');
        $this->assertReach($this->lade, $projectOnly, false, 'Lade is on no project');

        $this->assertReach($this->david, $projectOnly, true, 'David is on Alpha');
        $this->assertReach($this->david, $unitOnly, false, 'David is not in Structural');

        // John holds `assigned` only. Neither task is on him, and being on Alpha's team does not
        // help: project REACH needs an explicit project-scoped grant, which he does not hold for
        // tasks (docs/05 §6).
        $this->assertReach($this->john, $unitOnly, false, 'John holds assigned scope only');
        $this->assertReach($this->john, $projectOnly, false, 'John holds assigned scope only');

        // Tunde holds `task.view:project` but is on nothing at all.
        $this->assertReach($this->tunde, $projectOnly, false, 'Tunde is on no project');
    }

    #[Test]
    public function assigning_a_task_is_what_grants_sight_of_it(): void
    {
        // On Alpha, because Priya's `task.assign` is project-scoped: her authority over a task is
        // the project it belongs to. John is on Alpha's team too, and it makes no difference — he
        // holds `task.view:assigned`, so the membership row has no task-shaped grant to widen.
        $task = $this->task('Inspect the falsework', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->assertReach($this->john, $task, false, 'before assignment');

        $this->actingFresh($this->priya)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->john->id])
            ->assertRedirect(route('tasks.show', $task));

        app(ActorProfile::class)->flush();

        $this->assertReach($this->john, $task, true, 'after assignment');

        // And the grant is auditable: who put it on him, and when.
        $fresh = $task->fresh();
        $this->assertSame((int) $this->priya->id, (int) $fresh->assigned_by);
        $this->assertNotNull($fresh->assigned_at);
    }

    #[Test]
    public function confidentiality_is_not_lifted_by_being_assigned_the_work(): void
    {
        // docs/05 §4: above `internal` the record has to be reachable through the owning unit, the
        // project, or an explicit grant. Handing somebody the job is none of those.
        $task = $this->task('Prepare the settlement position paper', [
            'org_unit_id' => $this->delivery->id,
            'assignee_id' => $this->john->id,
            'confidentiality' => Confidentiality::Restricted,
        ]);

        app(ActorProfile::class)->flush();

        $this->assertReach($this->john, $task, false, 'restricted work assigned to him');

        // Priya reaches it through the project axis on a project she manages — so this is
        // confidentiality doing the work, not the fixture failing to save a row.
        $confidential = $this->task('Restricted but on Alpha', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
            'confidentiality' => Confidentiality::Restricted,
        ]);

        $this->assertReach($this->priya, $confidential, true, 'the project manager on her own project');
    }

    // ------------------------------------------------------------------ assignment as a grant

    #[Test]
    public function a_department_lead_may_task_their_own_people_and_nobody_elses(): void
    {
        $task = $this->task('Re-check the deck levels', ['org_unit_id' => $this->structural->id]);

        // His own department: the cascade working as designed.
        $this->actingFresh($this->lade)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->john->id])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame((int) $this->john->id, (int) $task->fresh()->assignee_id);

        // Somebody in another department: refused server-side, not merely absent from the picker.
        // This is the case the two-question policy exists for — one question would let a lead pinned
        // to Structural put work on anyone in the company and then read every reply on it (§84).
        $this->actingFresh($this->lade)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->tunde->id])
            ->assertForbidden();

        $this->assertSame((int) $this->john->id, (int) $task->fresh()->assignee_id);
    }

    #[Test]
    public function a_project_manager_may_task_someone_they_have_no_org_chart_authority_over(): void
    {
        // Priya sits in Project Delivery and has no authority over HSE at all. Her `task.assign` is
        // project-scoped and David is on Alpha, so the bound on her tasking is the job rather than a
        // subtree (docs/05 §6).
        $task = $this->task('Raise the lifting plan', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->actingFresh($this->priya)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->david->id])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame((int) $this->david->id, (int) $task->fresh()->assignee_id);

        // Tunde is in the same department as David and is not on the project. Same actor, same
        // permission, different answer — which is the project axis being a real bound.
        $this->actingFresh($this->priya)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->tunde->id])
            ->assertForbidden();
    }

    #[Test]
    public function assignment_cannot_ride_in_on_the_edit_form(): void
    {
        // The whole reason assignment has its own route: if `assignee_id` were an ordinary field,
        // anyone holding `task.edit` could grant sight of a record to anyone they could name,
        // without the candidate check ever running.
        $task = $this->task('Log the concrete pour', [
            'org_unit_id' => $this->structural->id,
            'assignee_id' => $this->john->id,
        ]);

        app(ActorProfile::class)->flush();

        $this->actingFresh($this->john)
            ->put(route('tasks.update', $task), [
                'title' => 'Log the concrete pour',
                'confidentiality' => Confidentiality::Internal->value,
                'priority' => 'normal',
                'assignee_id' => $this->tunde->id,
            ])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame(
            (int) $this->john->id,
            (int) $task->fresh()->assignee_id,
            'assignee_id was written by the general edit path, bypassing the candidate check',
        );
    }

    // ------------------------------------------------------------------ dependencies

    #[Test]
    public function a_task_cannot_be_closed_while_something_it_waits_on_is_open(): void
    {
        $blocker = $this->task('Approve the method statement', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $waiter = $this->task('Begin the pour', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->actingFresh($this->priya)
            ->post(route('tasks.dependencies.store', $waiter), ['depends_on_task_id' => $blocker->id])
            ->assertSessionHasNoErrors();

        // Get the waiter moving, then try to finish it.
        $this->move($waiter, 'in_progress');

        $done = $this->statusNamed('task', 'done');

        $this->actingFresh($this->priya)
            ->post(route('tasks.status', $waiter), ['status_id' => $done->id])
            ->assertSessionHasErrors('status_id');

        $this->assertFalse($waiter->fresh()->isClosed());

        // Cancelling is still allowed: abandoning blocked work is a real outcome, and refusing it
        // would leave the task open forever.
        $cancelled = $this->statusNamed('task', 'cancelled');

        $this->actingFresh($this->priya)
            ->post(route('tasks.status', $waiter), [
                'status_id' => $cancelled->id,
                'comment' => 'Superseded by a revised sequence.',
            ])
            ->assertRedirect(route('tasks.show', $waiter));

        $this->assertTrue($waiter->fresh()->isClosed());
    }

    #[Test]
    public function a_dependency_cycle_is_refused(): void
    {
        $a = $this->task('A', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);
        $b = $this->task('B', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);
        $c = $this->task('C', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);

        // A waits for B waits for C. Adding "C waits for A" would close the loop and make every
        // "can this start?" question unanswerable.
        $this->actingFresh($this->priya)
            ->post(route('tasks.dependencies.store', $a), ['depends_on_task_id' => $b->id])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->priya)
            ->post(route('tasks.dependencies.store', $b), ['depends_on_task_id' => $c->id])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->priya)
            ->post(route('tasks.dependencies.store', $c), ['depends_on_task_id' => $a->id])
            ->assertSessionHasErrors('depends_on_task_id');

        $this->assertSame(2, $this->withinOrganization($this->org, fn () => TaskDependency::query()->count()));

        // And the degenerate case, which a graph walk that starts one step in would miss.
        $this->actingFresh($this->priya)
            ->post(route('tasks.dependencies.store', $a), ['depends_on_task_id' => $a->id])
            ->assertSessionHasErrors('depends_on_task_id');
    }

    #[Test]
    public function a_blocking_task_the_actor_cannot_see_is_refused_rather_than_named(): void
    {
        // `existsInTenant` pins the id to this company, which stops the cross-tenant edge — but it
        // cannot run the confidentiality and scope gates. Without the controller's second check the
        // endpoint would answer "does this task exist here?" for any id somebody typed, and then
        // render its number and title on the page (§61, §84).
        $secret = $this->task('Contract termination assessment', [
            'org_unit_id' => $this->delivery->id,
            'confidentiality' => Confidentiality::Restricted,
        ]);

        $mine = $this->task('Draft the progress report', [
            'org_unit_id' => $this->structural->id,
        ]);

        $this->actingFresh($this->lade)
            ->post(route('tasks.dependencies.store', $mine), ['depends_on_task_id' => $secret->id])
            ->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => TaskDependency::query()->count()));
    }

    // ------------------------------------------------------------------ IDOR and tenancy

    #[Test]
    public function a_checklist_item_cannot_be_reached_through_another_tasks_url(): void
    {
        // §94's case on a nested route. The item id is real and Priya may edit both tasks; only the
        // pairing is wrong, and scoped bindings make that a 404 at the router rather than a policy
        // question asked about the wrong parent.
        $mine = $this->task('Task A', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);
        $other = $this->task('Task B', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);

        $item = $this->withinOrganization($this->org, fn () => app(TaskChecklistService::class)
            ->add($other, ['title' => 'Signed by the site engineer'], $this->priya));

        $this->actingFresh($this->priya)
            ->post(route('tasks.checklist.toggle', [$mine, $item]), ['is_done' => 1])
            ->assertNotFound();

        $this->actingFresh($this->priya)
            ->delete(route('tasks.checklist.destroy', [$mine, $item]))
            ->assertNotFound();

        $this->assertTrue($this->withinOrganization($this->org, fn () => TaskChecklistItem::query()
            ->whereKey($item->id)->exists()));

        // The correctly-paired request works, so the 404s above are the scoping and not a broken
        // route.
        $this->actingFresh($this->priya)
            ->post(route('tasks.checklist.toggle', [$other, $item]), ['is_done' => 1])
            ->assertRedirect();

        $this->assertTrue((bool) $this->withinOrganization($this->org, fn () => TaskChecklistItem::query()
            ->whereKey($item->id)->firstOrFail()->is_done));
    }

    #[Test]
    public function a_dependency_cannot_be_removed_through_another_tasks_url(): void
    {
        $a = $this->task('Task A', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);
        $b = $this->task('Task B', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);
        $c = $this->task('Task C', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);

        $edge = $this->withinOrganization($this->org, fn () => app(TaskDependencyService::class)
            ->add($b, $c, $this->priya));

        $this->actingFresh($this->priya)
            ->delete(route('tasks.dependencies.destroy', [$a, $edge]))
            ->assertNotFound();

        $this->assertTrue($this->withinOrganization($this->org, fn () => TaskDependency::query()
            ->whereKey($edge->id)->exists()));
    }

    #[Test]
    public function another_organization_gets_a_404_rather_than_a_refusal(): void
    {
        $other = $this->makeOrganization('Kestrel Facilities');
        $unit = $this->makeUnit($other, 'Operations', OrgUnitType::Department);

        $role = $this->makeRole($other, 'Administrator', [
            'task.view' => 'organization',
            'task.edit' => 'organization',
            'task.assign' => 'organization',
        ]);

        $outsider = $this->makePerson($other, 'Rhona Calder', $unit, [$role]);

        $task = $this->task('Ours alone', ['org_unit_id' => $this->structural->id]);

        // 404, not 403: the tenant scope makes the row not exist, so the answer does not confirm
        // that a task with this id is there to be asked about (§75).
        $this->actingFresh($outsider)->get(route('tasks.show', $task))->assertNotFound();
        $this->actingFresh($outsider)->get(route('tasks.edit', $task))->assertNotFound();

        $this->actingFresh($outsider)
            ->post(route('tasks.assign', $task), ['assignee_id' => $outsider->id])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ followers

    #[Test]
    public function following_a_task_grants_nothing(): void
    {
        // The rule the Follower model states in capitals. A subscription is a notification
        // preference; if the access engine ever read this table, anyone who could name a record
        // could subscribe themselves into it.
        $task = $this->task('Structural work Tunde has no business seeing', [
            'org_unit_id' => $this->structural->id,
        ]);

        $this->assertReach($this->tunde, $task, false, 'before the row exists');

        // Inserted directly, because the endpoint would rightly refuse him — the question here is
        // what the row itself does, not whether he can create one.
        $this->withinOrganization($this->org, fn () => Follower::create([
            'organization_id' => $this->org->id,
            'followable_type' => $task->getMorphClass(),
            'followable_id' => $task->getKey(),
            'user_id' => $this->tunde->id,
            'reason' => 'manual',
        ]));

        app(ActorProfile::class)->flush();

        $this->assertReach($this->tunde, $task, false, 'with a follower row in place');
    }

    #[Test]
    public function you_may_only_follow_what_you_can_already_see(): void
    {
        $task = $this->task('Structural work', ['org_unit_id' => $this->structural->id]);

        $this->actingFresh($this->tunde)
            ->post(route('tasks.follow', $task))
            ->assertForbidden();

        $this->actingFresh($this->lade)
            ->post(route('tasks.follow', $task))
            ->assertRedirect();

        $this->assertTrue($this->withinOrganization($this->org, fn () => Follower::query()
            ->where('followable_id', $task->id)
            ->where('user_id', $this->lade->id)
            ->exists()));
    }

    // ------------------------------------------------------------------ complete vs verify

    #[Test]
    public function the_doer_may_finish_the_work_but_not_accept_it(): void
    {
        // §16's separation, enforced by the transition catalogue rather than by a branch: the move
        // into `done` from `in_progress` needs `task.complete`; the move from `review` needs
        // `task.verify`. John holds the first and not the second.
        $task = $this->task('Fabricate the bracket', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->structural->id,
            'assignee_id' => $this->john->id,
        ]);

        app(ActorProfile::class)->flush();

        $this->move($task, 'in_progress', $this->john);
        $this->move($task, 'review', $this->john);

        $done = $this->statusNamed('task', 'done');

        $this->actingFresh($this->john)
            ->post(route('tasks.status', $task), ['status_id' => $done->id])
            ->assertSessionHasErrors('status_id');

        $this->assertFalse($task->fresh()->isClosed());

        // Priya holds task.verify on this project, so the same move succeeds for her — and closing
        // stamps who accepted it, which is the record the separation exists to produce.
        $this->actingFresh($this->priya)
            ->post(route('tasks.status', $task), ['status_id' => $done->id])
            ->assertRedirect(route('tasks.show', $task));

        $closed = $task->fresh();

        $this->assertTrue($closed->isClosed());
        $this->assertSame((int) $this->priya->id, (int) $closed->completed_by);
        $this->assertSame(100, (int) $closed->progress_percent);
    }

    #[Test]
    public function reach_is_not_authority(): void
    {
        // David can see the task through the project axis. `task.view:project` is all he holds, so
        // he may not edit it, assign it or move it (§14).
        $task = $this->task('Site walkdown', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->actingFresh($this->david)->get(route('tasks.show', $task))->assertOk();
        $this->actingFresh($this->david)->get(route('tasks.edit', $task))->assertForbidden();

        $this->actingFresh($this->david)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->david->id])
            ->assertForbidden();

        $this->assertNull($task->fresh()->assignee_id);
    }

    #[Test]
    public function a_task_with_work_hanging_off_it_is_not_deletable(): void
    {
        // §59: cancelling is the answer, so the history survives. The policy refuses the delete
        // regardless of permission, which is why Priya — who holds task.delete on this project —
        // is the right actor to prove it with.
        $parent = $this->task('Parent', ['project_id' => $this->alpha->id, 'org_unit_id' => $this->delivery->id]);
        $child = $this->task('Child', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
            'parent_id' => $parent->id,
        ]);

        $this->actingFresh($this->priya)
            ->delete(route('tasks.destroy', $parent))
            ->assertForbidden();

        // The leaf has nothing depending on it, so it goes.
        $this->actingFresh($this->priya)
            ->delete(route('tasks.destroy', $child))
            ->assertRedirect(route('tasks.index'));
    }

    // ------------------------------------------------------------------ helpers

    /** @param  array<string,mixed>  $attributes */
    private function task(string $title, array $attributes = []): Task
    {
        $task = $this->makeTask($this->org, $this->priya, $title, $attributes);

        app(ActorProfile::class)->flush();

        return $task;
    }

    /**
     * Assert both halves of "can this person see this record": the record page and the compiled
     * list. A page you can open that never appears in your list — or the reverse — is the bug §61
     * is about, and only checking both catches it.
     */
    private function assertReach(User $person, Task $task, bool $expected, string $because): void
    {
        $response = $this->actingFresh($person)->get(route('tasks.show', $task));

        $this->assertSame(
            $expected ? 200 : 403,
            $response->status(),
            $because.': the task page answered '.$response->status().'.',
        );

        $this->assertSame(
            $expected,
            $this->visibleIdsFor($person)->contains($task->id),
            $because.': the compiled list disagrees with the task page.',
        );
    }

    private function move(Task $task, string $key, ?User $actor = null): void
    {
        $status = $this->statusNamed('task', $key);

        $this->actingFresh($actor ?? $this->priya)
            ->post(route('tasks.status', $task), ['status_id' => $status->id, 'comment' => 'Moving on.'])
            ->assertSessionHasNoErrors();
    }

    private function addToTeam(Project $project, User $person): void
    {
        $this->withinOrganization($this->org, fn () => app(ProjectTeamService::class)
            ->addMember($project, ['user_id' => $person->id], $this->priya));

        app(ActorProfile::class)->flush();
    }

    private function statusNamed(string $entityType, string $key): Status
    {
        return $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity($entityType)
            ->where('key', $key)
            ->firstOrFail());
    }

    /** The ids the access engine compiles for this person — the list behind the register page. */
    private function visibleIdsFor(User $person): Collection
    {
        return $this->withinOrganization(
            $this->org,
            fn () => Task::visibleTo($person, 'task.view')->pluck('id'),
        );
    }
}
