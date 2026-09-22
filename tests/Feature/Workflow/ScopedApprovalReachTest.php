<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Workflow\Concerns\HasWorkflow;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * An approver whose authority is bounded by a department must be able to use it (§6, §25, §78).
 *
 * WHY THE REST OF THE WORKFLOW SUITE CANNOT SEE THIS. Every approver fixture in
 * `WorkflowEngineTest`, `EscalationTest` and their neighbours grants `approval.act` at
 * `organization`, because organization scope is the one that always works and a fixture is written
 * to get to the interesting assertion. Organization scope compiles to `1 = 1`. It is the single
 * value of that field which cannot distinguish a working scope predicate from a broken one, and it
 * is the value 38 passing tests were all using.
 *
 * WHAT WAS BROKEN UNDERNEATH THEM. `ScopeCompiler` resolves a unit-scoped grant from the record's
 * own `org_unit_id`, or by recursing through `accessParentRelation()` to a model that has one. A
 * `WorkflowStageInstance` had no such column and named no parent — its subject hangs off a
 * polymorphic pair `whereHas()` cannot walk — so the compiler reached its last branch and wrote
 * `1 = 0`. Every department- and project-scoped `approval.*` grant in the system selected no rows.
 * Six of the shipped role templates are built on exactly those scopes: Department Head, Engineering
 * Manager, Lead Engineer, Project Director, Project Manager, HSE Officer.
 *
 * AND THE INBOX DID NOT SHOW IT. `ApprovalController::index` narrows to the frozen assignee lists
 * and then filters each row by its SUBJECT's policy — `document.view`, not `approval.act`. So a
 * Department Head saw the row, opened the record, read it, pressed approve and got a 403 with no
 * explanation, on a stage the system had asked them by name to decide. An empty inbox would at
 * least have been a symptom somebody could report. This was a dead button.
 *
 * THE THREE CLAIMS BELOW ARE ONE CLAIM IN THREE PARTS, and the middle one is the one that keeps the
 * fix honest. Making approvals reachable is trivial if reach stops meaning anything — granting the
 * permission organization-wide would turn the first test green and quietly hand every department
 * head in the company authority over every other department's approvals. So: the approver inside
 * the department can act, the approver in the sibling department cannot, and when the record moves
 * between them both answers swap over.
 *
 * @see ScopeCompiler for the predicate being exercised
 * @see HasWorkflow for the move that keeps reach current
 */
final class ScopedApprovalReachTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $mechanical;

    private Role $manager;

    private User $ngozi;    // document controller, Civil: writes and submits

    private User $chidi;    // engineering manager, Civil: department-scoped approver

    private User $amaka;    // engineering manager, Mechanical: the control

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Harmattan Engineering');
        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->mechanical = $this->makeUnit($this->org, 'Mechanical', OrgUnitType::Department, $engineering);

        // The scope under test. `document.view` is department-scoped too, because §68 says an
        // approver must be able to read what they are being asked to approve — an approval right
        // that outruns the read right is a notification about a record the recipient cannot open.
        $this->manager = $manager = $this->makeRole($this->org, 'Engineering Manager', [
            'document.view' => 'department',
            'approval.view' => 'department',
            'approval.act' => 'department',
        ]);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document.manage' => 'organization',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [$manager]);
        $this->amaka = $this->makePerson($this->org, 'Amaka Obi', $this->mechanical, [$manager]);
    }

    #[Test]
    public function a_department_scoped_approver_can_decide_a_stage_in_their_own_department(): void
    {
        $stage = $this->submitDocumentFrom($this->civil);

        // Assignment and reach are different questions and both have to be yes. Resolution reads
        // the org chart, so it was always right; reach reads the permission compiler, and that is
        // the half that used to answer no.
        $this->assertTrue($stage->isAssignedTo($this->chidi), 'The stage did not resolve onto the department manager.');

        // The inbox is the weaker of the two assertions and is here for contrast, not for proof:
        // it filters by the subject's own policy, so it was green throughout the defect. The line
        // below it is the one that was red.
        $this->actingFresh($this->chidi)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Shoring and excavation');

        $this->actingFresh($this->chidi)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'approve',
                'comment' => 'Trench box detail matches the ground investigation.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($stage->refresh()->isPending(), 'The decision did not close the stage.');
    }

    #[Test]
    public function the_same_role_in_a_sibling_department_reaches_nothing(): void
    {
        $stage = $this->submitDocumentFrom($this->civil);

        // Amaka is ASSIGNED to this stage — same role, same resolution, and the quorum is `any`, so
        // the engine would take her decision and close it. The only difference between her and
        // Chidi is which department she stands in, which is the whole meaning of the word.
        $this->assertTrue($stage->isAssignedTo($this->amaka), 'The fixture no longer assigns the control.');

        // §68: she is assigned, so the engine offers her the row, and the subject filter drops it
        // because a Civil document is not hers to read. That is a separate mechanism from the one
        // under test, and it is the reason the defect below stayed invisible for so long.
        $this->actingFresh($this->amaka)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertDontSee('Shoring and excavation');

        $this->actingFresh($this->amaka)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'approve',
                'comment' => 'Looks fine to me.',
            ])
            ->assertForbidden();

        $this->assertTrue($stage->refresh()->isPending(), 'A neighbouring department decided this stage.');
    }

    #[Test]
    public function moving_the_record_between_departments_moves_the_reach_with_it(): void
    {
        $stage = $this->submitDocumentFrom($this->civil);
        $document = $stage->instance->subject;

        // §74: the record is reorganized mid-approval. Nothing advances the route, so if reach were
        // only refreshed at stage transitions this would still be addressed to Civil.
        $document->forceFill(['org_unit_id' => $this->mechanical->id])->save();

        $this->assertSame(
            $this->mechanical->id,
            (int) $stage->instance->refresh()->org_unit_id,
            'The in-flight approval stayed pointed at the department the record has left.',
        );

        // Both are still ADDRESSED: assignees are frozen at stage entry, and a reorganization is
        // not a reason to pretend nobody was asked. What has changed is which of them can act.
        $this->assertTrue($stage->refresh()->isAssignedTo($this->chidi));
        $this->assertTrue($stage->isAssignedTo($this->amaka));

        // Chidi was the one who could decide this a moment ago. Now the record is not in his
        // department, and §25's escalation ladder is what is supposed to move it off him.
        $this->actingFresh($this->chidi)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'approve',
                'comment' => 'Still happy with it.',
            ])
            ->assertForbidden();

        // And Amaka, who could not touch it a moment ago, now can.
        $this->actingFresh($this->amaka)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'approve',
                'comment' => 'Reviewed against the mechanical scope.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($stage->refresh()->isPending(), 'The new department could not close the stage.');
    }

    /**
     * A document raised in $unit, routed through a one-stage department-manager approval.
     */
    private function submitDocumentFrom(OrgUnit $unit): WorkflowStageInstance
    {
        $workflow = $this->makeWorkflow($this->org, $this->ngozi, 'Method statement approval', 'document', [
            [
                'name' => 'Engineering review',
                'sequence' => 1,
                // A ROLE, resolved across the whole company: both managers are put on this stage,
                // because stage resolution reads the org chart and the role, not the permission
                // scope. That is the point. Assignment and reach are two different questions, and
                // this fixture is built so that only the second one can distinguish the two people.
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->manager->id,
                // Either of them, so one decision closes the stage and "it is still open" means
                // somebody was refused rather than that the engine was waiting for a second vote.
                'quorum' => 'any',
            ],
        ]);

        $category = $this->makeDocumentCategory($this->org, 'Method Statements', [
            'requires_approval' => true,
            'workflow_id' => $workflow->id,
        ]);

        $document = $this->makeDocument($this->org, $this->ngozi, 'Shoring and excavation below 1.2 m', [
            'document_category_id' => $category->id,
            'org_unit_id' => $unit->id,
        ]);

        $this->makeNativeVersion($this->org, $document, $this->ngozi);

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $instance = $document->refresh()->currentWorkflowInstance();

        $this->assertNotNull($instance, 'Submitting produced no workflow instance.');

        $stage = WorkflowStageInstance::query()->whereKey($instance->current_stage_instance_id)->first();

        $this->assertNotNull($stage, 'The instance opened no stage.');

        return $stage;
    }
}
