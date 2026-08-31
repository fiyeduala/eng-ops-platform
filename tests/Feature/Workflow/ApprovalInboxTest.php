<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * My Approvals — the screen the workflow engine exists to fill, and the one place a leak costs most.
 *
 * WorkflowEngineTest proves a route can be defined and travelled. This file is about the other
 * half: who gets to see that it is travelling, and who gets to answer. Those are different
 * questions, and a system that conflates them fails in both directions — it either hides work from
 * the person who has to do it, or shows a confidential title to somebody who was named on a stage
 * by an org chart that knows nothing about confidentiality.
 *
 * FOUR THINGS ARE BEING PINNED HERE, each of which has a section of the brief behind it:
 *
 *   · THE FROZEN LIST DECIDES, NOT THE URL (§84, §94). Every test that tries to act does so by
 *     POSTing a perfectly valid stage id as somebody who is not on that stage's assignee list. The
 *     id is real, the route model binds, the permission is held — and it is still 403, because the
 *     answer comes from `resolved_assignees` rather than from the request. That is the IDOR case
 *     the brief names explicitly, aimed at the most consequential POST in the platform.
 *
 *   · THE INBOX FILTERS TWICE (§61, §68). The engine narrows to the stages this person is on; the
 *     controller then puts every row's SUBJECT through the policy and drops what they may not read.
 *     The second pass is not redundant — assignee lists are frozen, so somebody can legitimately be
 *     on a stage for a record whose confidentiality was raised afterwards. The notification obeys
 *     the same rule, and the test for it asserts on the notifications table rather than on the
 *     screen, because a leak by email is still a leak.
 *
 *   · DELEGATING AND REASSIGNING ARE DIFFERENT ACTS. One is participatory and belongs to the person
 *     holding the decision; the other is administrative and is deliberately denied to them. Letting
 *     an assignee redirect their own approval turns "I will not approve this" into somebody else's
 *     problem with no rejection anybody can see.
 *
 *   · TENANCY IS ABSENT FROM THE ASSERTIONS BECAUSE IT IS ABSENT FROM THE RESULTS (§75). A stage in
 *     another organization does not 403 — it 404s, because the tenant scope means it was never
 *     found. The distinction matters: a 403 confirms the record exists.
 */
final class ApprovalInboxTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private Role $leadEngineer;

    private Role $controllerRole;

    private User $adaeze;   // administrator: builds routes, reassigns, cancels

    private User $ngozi;    // document controller: writes and submits

    private User $tunde;    // lead engineer: the assignee in most tests

    private User $chidi;    // an approver in his own right — but never the one the stage named

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);

        $this->leadEngineer = $this->makeRole($this->org, 'Lead Engineer', [
            'document.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
            'approval.delegate' => 'organization',
        ]);

        // The author holds `approval.view` so she can watch where her own document is, and
        // `approval.act` so the self-approval test is refused by the RULE rather than by a
        // missing permission — a test that passes for the wrong reason proves nothing.
        $this->controllerRole = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document.manage' => 'organization',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
        ]);

        $administrator = $this->makeRole($this->org, 'Process Administrator', [
            'workflow.view' => 'organization',
            'workflow.create' => 'organization',
            'workflow.edit' => 'organization',
            'workflow.manage' => 'organization',
            'workflow.publish' => 'organization',
            'document_category.view' => 'organization',
            'document_category.manage' => 'organization',
            'approval.view' => 'organization',
            'approval.reassign' => 'organization',
            'role.view' => 'organization',
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        // Chidi is deliberately given a SEPARATE role carrying the same permissions rather than
        // the one the stages name. A role stage resolves to every holder of that role, so putting
        // him on Lead Engineer would have frozen him onto the stage legitimately and the IDOR test
        // below would have been asserting nothing. What makes him the right adversary is that he
        // holds `approval.act` in full and is still not on this particular list.
        $peerRole = $this->makeRole($this->org, 'Senior Engineer', [
            'document.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
            'approval.delegate' => 'organization',
        ]);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Obi', $this->engineering, [$administrator]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$this->controllerRole]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', $this->civil, [$this->leadEngineer]);
        $this->chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [$peerRole]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => true,
            'workflow_id' => null,
            'draft_revision_prefix' => 'P',
        ]);
    }

    // ================================================================== the four views

    #[Test]
    public function the_inbox_lists_what_is_waiting_on_the_person_reading_it_and_nothing_else(): void
    {
        [$document] = $this->submitOn($this->leadEngineer);

        // Tunde was the one the stage resolved to.
        $this->actingFresh($this->tunde)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Excavation and shoring procedure')
            ->assertSee('1 thing is waiting on you');

        // Chidi approves things for a living too. This one is not his, so it is not in his inbox —
        // an inbox that showed it would have two people each assuming the other had answered.
        $this->actingFresh($this->chidi)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertDontSee('Excavation and shoring procedure')
            ->assertSee('Nothing is waiting on you');

        $this->assertNotNull($this->instanceFor($document));
    }

    #[Test]
    public function the_overdue_view_lists_only_what_has_run_past_its_agreed_turnaround(): void
    {
        $this->submitOn($this->leadEngineer, ['sla_hours' => '4']);

        // Inside the agreed four hours, this is work in hand — not a failure.
        $this->actingFresh($this->tunde)
            ->get(route('approvals.index', ['view' => 'overdue']))
            ->assertOk()
            ->assertDontSee('Excavation and shoring procedure')
            ->assertSee('Nothing is overdue');

        $this->travel(5)->hours();

        $this->actingFresh($this->tunde)
            ->get(route('approvals.index', ['view' => 'overdue']))
            ->assertOk()
            ->assertSee('Excavation and shoring procedure');

        $this->travelBack();
    }

    #[Test]
    public function a_decision_moves_from_waiting_on_me_to_i_have_decided(): void
    {
        [, $stage] = $this->submitOn($this->leadEngineer);

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('approvals.index'));

        $this->actingFresh($this->tunde)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertDontSee('Excavation and shoring procedure');

        // Kept permanently: a decision is part of the record, not a notification that expires.
        $this->actingFresh($this->tunde)
            ->get(route('approvals.index', ['view' => 'decided']))
            ->assertOk()
            ->assertSee('Excavation and shoring procedure');
    }

    #[Test]
    public function the_submitted_view_tells_an_author_where_their_own_document_is(): void
    {
        $this->submitOn($this->leadEngineer);

        // Ngozi is nobody's approver here. This view is the answer to "where is my document",
        // which is otherwise a question people ask by email.
        $this->actingFresh($this->ngozi)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Nothing is waiting on you');

        $this->actingFresh($this->ngozi)
            ->get(route('approvals.index', ['view' => 'started']))
            ->assertOk()
            ->assertSee('Excavation and shoring procedure');
    }

    // ================================================================== §84 / §94 — the id is not the authority

    #[Test]
    public function a_person_not_on_the_frozen_list_cannot_decide_by_posting_a_valid_stage_id(): void
    {
        [, $stage] = $this->submitOn($this->leadEngineer);

        // Chidi holds `approval.act` organization-wide and is posting a real stage id in his own
        // tenant, signed in as himself. Every ingredient an IDOR needs except the one that decides.
        $this->actingFresh($this->chidi)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertForbidden();

        $stage->refresh();

        $this->assertSame(WorkflowStageInstance::STATUS_PENDING, $stage->status);
        $this->assertNull($stage->acted_by);
        $this->assertSame(0, $stage->approvals()->count());
    }

    #[Test]
    public function an_author_is_never_put_on_a_stage_that_would_approve_their_own_work(): void
    {
        // The route names the CONTROLLER role, whose only holder is the person who submitted the
        // document. Approving your own submission is the most common audit finding in document
        // control, which is why `allow_self_approval` defaults to false.
        [$document, $stage] = $this->submitOn($this->controllerRole);

        $this->assertSame((int) $this->ngozi->getKey(), $document->workflowOwnerId());

        // The exclusion happens at RESOLUTION, not at action time — so the frozen list itself
        // never contains the author. That is the stronger place for it: the inbox never offers
        // her a decision she cannot make, and a quorum of three cannot quietly become a quorum of
        // two-plus-the-author.
        $this->assertFalse($stage->isAssignedTo($this->ngozi));
        $this->assertSame([], $stage->assigneeIds());
        $this->assertStringContainsString('does not permit self-approval', (string) $stage->resolution_note);

        // Removing her emptied the list, and the engine STOPS AND SAYS SO rather than skipping the
        // approval. A stage whose only possible approver wrote the thing is a broken configuration,
        // and it should surface as something somebody investigates — never as an approval that
        // quietly went nowhere.
        $instance = $this->instanceFor($document);
        $this->assertSame(WorkflowInstance::STATUS_STALLED, $instance?->status);
        $this->assertNull($document->refresh()->current_version_id);

        $this->actingFresh($this->ngozi)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingFresh($this->ngozi)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Nothing is waiting on you');
    }

    #[Test]
    public function one_person_gets_one_decision(): void
    {
        [, $stage] = $this->submitOn($this->leadEngineer, ['quorum' => 'any']);

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        // The stage has closed, so this is a decision on nothing. Accepting it would let a late
        // answer overwrite the outcome of a route that has already moved on.
        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), ['decision' => 'reject', 'comment' => 'Changed my mind.'])
            ->assertForbidden();

        $this->assertSame(1, $stage->refresh()->approvals()->count());
    }

    // ================================================================== §75 — another tenant's approval does not exist

    #[Test]
    public function an_approval_belonging_to_another_organization_is_not_found(): void
    {
        [, $stage] = $this->submitOn($this->leadEngineer);

        $other = $this->makeOrganization('Kestrel Infrastructure');
        $otherUnit = $this->makeUnit($other, 'Projects', OrgUnitType::Department);
        $otherRole = $this->makeRole($other, 'Reviewer', [
            'document.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
            'approval.reassign' => 'organization',
        ]);
        $intruder = $this->makePerson($other, 'Sade Ajayi', $otherUnit, [$otherRole]);

        // Not 403 — 404. A 403 would confirm the record exists, which is itself a disclosure.
        $this->actingFresh($intruder)
            ->get(route('approvals.show', $stage))
            ->assertNotFound();

        $this->actingFresh($intruder)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertNotFound();

        $this->actingFresh($intruder)
            ->post(route('approvals.reassign', $stage), ['assignees' => [$intruder->id]])
            ->assertNotFound();

        $this->assertSame(WorkflowStageInstance::STATUS_PENDING, $stage->refresh()->status);
    }

    // ================================================================== delegation vs reassignment

    #[Test]
    public function delegating_hands_the_decision_over_rather_than_sharing_it(): void
    {
        [, $stage] = $this->submitOn($this->leadEngineer);

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'delegate',
                'delegate_to' => $this->chidi->id,
                'comment' => 'On leave until the 14th.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('approvals.index'));

        $stage->refresh();

        // The frozen list was EDITED, not bypassed: the delegate takes the delegator's place.
        // Adding without removing would leave two people able to answer one stage.
        $this->assertTrue($stage->isAssignedTo($this->chidi));
        $this->assertFalse($stage->isAssignedTo($this->tunde));
        $this->assertSame(WorkflowStageInstance::STATUS_PENDING, $stage->status);

        $this->actingFresh($this->chidi)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Excavation and shoring procedure');

        // Handing the decision on IS his answer, so he cannot then also vote.
        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingFresh($this->chidi)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame(WorkflowStageInstance::STATUS_APPROVED, $stage->refresh()->status);
    }

    #[Test]
    public function reassignment_is_the_administrators_unstick_button_and_not_the_assignees(): void
    {
        [, $stage] = $this->submitOn($this->leadEngineer);

        // Being asked to approve something does not give you the authority to decide who approves
        // it instead. Otherwise "I will not approve this" becomes somebody else's problem with no
        // rejection anybody can see.
        $this->actingFresh($this->tunde)
            ->post(route('approvals.reassign', $stage), ['assignees' => [$this->chidi->id]])
            ->assertForbidden();

        $this->assertTrue($stage->refresh()->isAssignedTo($this->tunde));

        $this->actingFresh($this->adaeze)
            ->post(route('approvals.reassign', $stage), [
                'assignees' => [$this->chidi->id],
                'reason' => 'Tunde has left the company.',
            ])
            ->assertSessionHasNoErrors();

        $stage->refresh();

        $this->assertTrue($stage->isAssignedTo($this->chidi));
        $this->assertFalse($stage->isAssignedTo($this->tunde));
        $this->assertStringContainsString('Adaeze Obi', (string) $stage->resolution_note);

        // Replaced, not added: the original assignee can no longer act on something he was
        // taken off.
        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertForbidden();
    }

    #[Test]
    public function cancelling_a_route_takes_the_document_out_of_approval_without_deleting_it(): void
    {
        [$document, $stage] = $this->submitOn($this->leadEngineer);
        $instance = $this->instanceFor($document);

        $this->actingFresh($this->adaeze)
            ->post(route('approvals.cancel', $instance), ['reason' => 'Superseded by a client change.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(WorkflowStageInstance::STATUS_CANCELLED, $stage->refresh()->status);
        $this->assertSame(WorkflowInstance::STATUS_CANCELLED, $instance->refresh()->status);

        // The record stays. The approval does not.
        $this->assertNotNull(Document::withoutGlobalScopes()->find($document->getKey()));

        $this->actingFresh($this->tunde)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertDontSee('Excavation and shoring procedure');
    }

    // ================================================================== §68 — never tell somebody what they may not read

    #[Test]
    public function somebody_who_cannot_read_the_document_is_neither_told_about_it_nor_shown_it(): void
    {
        [$document, $stage] = $this->submitOn($this->leadEngineer);

        // Positive control first: the person who CAN read it was told, so a later absence means
        // "filtered" rather than "notifications are not running in this test".
        $this->assertSame(1, $this->approvalNotificationsFor($this->tunde));

        // Bala can act on approvals but can only see documents he owns. An org chart put him on a
        // stage; the policy says he cannot open the record behind it. The right outcome is that he
        // is not told — not that a document title reaches him by email.
        $blinkered = $this->makeRole($this->org, 'Site Engineer', [
            'document.view' => 'own',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
        ]);
        $bala = $this->makePerson($this->org, 'Bala Musa', $this->civil, [$blinkered]);

        $this->actingFresh($this->adaeze)
            ->post(route('approvals.reassign', $stage), ['assignees' => [$bala->id]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($stage->refresh()->isAssignedTo($bala));

        // Asserted on the table rather than on the screen: a leak by email is still a leak.
        $this->assertSame(0, $this->approvalNotificationsFor($bala));

        // And the inbox's second filter drops the row, so the count above the list stays honest.
        $this->actingFresh($bala)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertDontSee('Excavation and shoring procedure');

        $this->actingFresh($bala)
            ->get(route('approvals.show', $stage))
            ->assertForbidden();

        $this->assertNotNull($document->refresh());
    }

    #[Test]
    public function the_notification_says_what_is_wanted_and_not_what_the_document_contains(): void
    {
        $this->submitOn($this->leadEngineer);

        $notification = Notification::withoutGlobalScopes()
            ->where('user_id', $this->tunde->getKey())
            ->where('key', 'approval.requested')
            ->firstOrFail();

        // The body may name the stage and the deadline. It may never carry the record's contents:
        // an identifier is not a summary, and people holding only `approval.act` see this text.
        $this->assertStringNotContainsString('Shore below', (string) $notification->body);
        $this->assertStringContainsString('Technical review', (string) $notification->body);
    }

    // ================================================================== fixture

    /**
     * A published one-stage route assigned to $role, attached to the category, with a document
     * already sitting on stage one.
     *
     * Built through the same HTTP endpoints the builder screen posts to, for the same reason as in
     * WorkflowEngineTest: a fixture that reached for the services would test a configuration no
     * administrator could actually have produced.
     *
     * @param  array<string,mixed>  $stageOverrides
     * @return array{0: Document, 1: WorkflowStageInstance}
     */
    private function submitOn(Role $role, array $stageOverrides = []): array
    {
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Procedure approval',
                'entity_type' => 'document',
                'is_default' => '1',
            ])->assertSessionHasNoErrors();

        $workflow = Workflow::withoutGlobalScopes()
            ->where('name', 'Procedure approval')->firstOrFail();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), [
                'name' => 'Technical review',
                'action_type' => 'approve',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $role->id,
                'quorum' => 'all',
                'on_reject' => 'return_to_owner',
                'allow_delegate' => '1',
                'allow_self_approval' => '0',
                'is_active' => '1',
                ...$stageOverrides,
            ])->assertSessionHasNoErrors();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))
            ->assertSessionHasNoErrors();

        $this->procedures->forceFill(['workflow_id' => $workflow->id])->save();

        $document = $this->makeDocument($this->org, $this->ngozi, 'Excavation and shoring procedure', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
        ]);

        $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertSessionHasNoErrors();

        $instance = $this->instanceFor($document->refresh());

        $this->assertNotNull($instance, 'The document did not enter a workflow.');

        return [$document, $this->currentStage($instance)];
    }

    private function instanceFor(Document $document): ?WorkflowInstance
    {
        return WorkflowInstance::withoutGlobalScopes()
            ->where('subject_type', 'document')
            ->where('subject_id', $document->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The stage the record is actually sitting on.
     *
     * Read from `current_stage_instance_id` because that column IS the engine's answer. Inferring
     * it from statuses would let the test agree with itself while disagreeing with the engine.
     */
    private function currentStage(WorkflowInstance $instance): WorkflowStageInstance
    {
        return WorkflowStageInstance::withoutGlobalScopes()
            ->whereKey($instance->current_stage_instance_id)
            ->firstOrFail();
    }

    private function approvalNotificationsFor(User $user): int
    {
        return Notification::withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('key', 'approval.requested')
            ->count();
    }
}
