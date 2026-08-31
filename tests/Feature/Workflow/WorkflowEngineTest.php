<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\Signature;
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
 * PHASE 3'S EXIT CRITERION, and the reason the workflow engine exists.
 *
 * The claim being tested is not "documents can be approved". It is: an administrator with no access
 * to the source code can define an approval route ENTIRELY THROUGH THE UI, and a real document then
 * travels it. So the route in the first test is built by POSTing to the same endpoints the builder
 * screen posts to — no factories, no service calls, nothing seeded. If any of that could only be
 * done from code, this test would not compile, which is exactly the failure that should be loud.
 *
 * The engine's own invariants are tested alongside it, because each one is a way a configurable
 * approval system silently goes wrong:
 *
 *   · A DEFINITION IS NEVER MUTATED IN PLACE. Editing a published route creates a new version; the
 *     documents already travelling the old one keep travelling the old one. Otherwise changing a
 *     route retroactively rewrites what people already approved.
 *
 *   · ASSIGNEES ARE FROZEN AT STAGE ENTRY. Who has to approve is decided when the stage opens, not
 *     when somebody opens the page. Otherwise a reorganisation mid-approval changes who was
 *     required, and the audit trail no longer says what actually happened.
 *
 *   · PUBLISHING VALIDATES. A route with a stage that resolves to nobody stalls forever, silently,
 *     and the first anybody knows is a client asking why a drawing has not come back.
 */
final class WorkflowEngineTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private Role $leadEngineer;

    private Role $director;

    private User $adaeze;   // process administrator: builds the route

    private User $ngozi;    // document controller: writes and submits

    private User $tunde;    // lead engineer: stage 1

    private User $yusuf;    // director: stage 2

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);

        // The people who APPROVE hold `approval.*` and enough document reach to read what they are
        // being asked to approve — §68 in the permissions rather than in a later patch.
        $this->leadEngineer = $this->makeRole($this->org, 'Lead Engineer', [
            'document.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
            'approval.delegate' => 'organization',
        ]);

        $this->director = $this->makeRole($this->org, 'Director', [
            'document.view' => 'organization',
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
            'role.view' => 'organization',
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document.manage' => 'organization',
            'org_unit.view' => 'organization',
            'document_category.view' => 'organization',
        ]);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Obi', $this->engineering, [$administrator]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', $this->civil, [$this->leadEngineer]);
        $this->yusuf = $this->makePerson($this->org, 'Yusuf Danladi', $this->engineering, [$this->director]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => true,
            'workflow_id' => null,
            'draft_revision_prefix' => 'P',
        ]);
    }

    // ================================================================== the exit criterion

    #[Test]
    public function a_workflow_defined_entirely_through_the_ui_routes_a_document_through_approval(): void
    {
        // ---- 1. The administrator defines the route. Nothing below touches a service. ----------
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Procedure approval',
                'description' => 'Technical review, then endorsement by a director.',
                'entity_type' => 'document',
                'is_default' => '1',
            ])
            ->assertRedirect();

        $workflow = Workflow::withoutGlobalScopes()->where('name', 'Procedure approval')->firstOrFail();

        $this->assertSame(1, (int) $workflow->version);
        $this->assertNull($workflow->published_at, 'A route must be born a draft, not live.');

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'Technical review',
                'instructions' => 'Check the method against the specification.',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->leadEngineer->id,
                'sla_hours' => '48',
            ]))
            ->assertRedirect();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'Director endorsement',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->director->id,
            ]))
            ->assertRedirect();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))
            ->assertRedirect();

        $workflow->refresh();
        $this->assertNotNull($workflow->published_at, 'Publishing did not take.');

        // ---- 2. The administrator points the register at it. Also through the UI. -------------
        $this->actingFresh($this->adaeze)
            ->put(route('document-categories.update', $this->procedures), [
                'name' => 'Procedures',
                'workflow_id' => (string) $workflow->id,
                'requires_approval' => '1',
                'revision_scheme' => 'alpha',
                'default_confidentiality' => 'internal',
                'default_kind' => 'native',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame((int) $workflow->id, (int) $this->procedures->refresh()->workflow_id);

        // ---- 3. A real document travels it. ----------------------------------------------------
        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect();

        $instance = $this->instanceFor($document);
        $this->assertNotNull($instance, 'Submitting produced no workflow instance: the route was not picked up.');

        // Stage 1 is open and waiting on Tunde — resolved from the ROLE the administrator chose,
        // never named directly.
        $stageOne = $this->currentStage($instance);
        $this->assertSame('Technical review', $stageOne->stage_name);
        $this->assertTrue($stageOne->isAssignedTo($this->tunde));
        $this->assertFalse($stageOne->isAssignedTo($this->yusuf), 'Stage 2 was opened at the same time as stage 1.');

        // It is in his inbox, and not in the director's yet.
        $this->actingFresh($this->tunde)->get(route('approvals.index'))
            ->assertOk()->assertSee($document->title, false);

        $this->actingFresh($this->yusuf)->get(route('approvals.index'))
            ->assertOk()->assertDontSee($document->title, false);

        // ---- 4. Stage 1 decides. ---------------------------------------------------------------
        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stageOne), [
                'decision' => 'approve',
                'comment' => 'Method matches the specification.',
            ])
            ->assertRedirect();

        $stageTwo = $this->currentStage($instance->refresh());
        $this->assertSame('Director endorsement', $stageTwo->stage_name);
        $this->assertTrue($stageTwo->isAssignedTo($this->yusuf));

        // The document is not approved yet, and must not read as though it were.
        $this->assertNull($version->refresh()->approved_at);
        $this->assertSame('P01', $version->revision);

        // ---- 5. Stage 2 decides, and the document is issued. -----------------------------------
        $this->actingFresh($this->yusuf)
            ->post(route('approvals.decide', $stageTwo), ['decision' => 'approve'])
            ->assertRedirect();

        $version->refresh();

        $this->assertNotNull($version->approved_at);
        $this->assertSame((int) $this->yusuf->id, (int) $version->approved_by);
        $this->assertSame('A', $version->revision, 'The draft was issued without being promoted.');
        $this->assertTrue((bool) $version->is_current);
        $this->assertSame((int) $version->id, (int) $document->refresh()->current_version_id);
        $this->assertSame('approved', $document->status->key);
        $this->assertFalse($document->isInWorkflow());
    }

    // ================================================================== rejection

    #[Test]
    public function a_rejection_sends_the_document_back_to_its_author_rather_than_ending_it(): void
    {
        [$document, $version] = $this->submittedDocument();

        $stage = $this->currentStage($this->instanceFor($document));

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'request_revision',
                'comment' => 'Clause 4.2 contradicts the specification. Please rework it.',
            ])
            ->assertRedirect();

        $document->refresh();
        $version->refresh();

        // Back to the author, still a draft, still editable — and the reason is on the record where
        // the author will actually read it.
        $this->assertNull($version->approved_at);
        $this->assertSame('P01', $version->revision);
        $this->assertFalse($document->isInWorkflow(), 'The document is still stuck in the route it was sent back from.');

        $this->actingFresh($this->ngozi)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Clause 4.2 contradicts the specification.', false);

        // And it can go round again.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect();

        $this->assertTrue($document->refresh()->isInWorkflow());
    }

    #[Test]
    public function a_comment_is_required_to_refuse_but_not_to_approve(): void
    {
        [$document] = $this->submittedDocument();
        $stage = $this->currentStage($this->instanceFor($document));

        // Refusing without saying why leaves the author with nothing to act on.
        $this->actingFresh($this->tunde)
            ->from(route('approvals.show', $stage))
            ->post(route('approvals.decide', $stage), ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');

        $this->assertTrue($stage->refresh()->isPending());

        // Agreeing needs no explanation.
        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function returning_to_the_previous_stage_reopens_the_one_immediately_before(): void
    {
        // Three stages, so "previous" and "first" are different answers. They were the same answer
        // for a while: the query asked for the latest approved stage but inherited an ascending
        // order from the relation and got the earliest, which on a rejection at stage three would
        // reopen stage one and throw away stage two's approval without saying so.
        $workflow = $this->buildRoute([
            ['name' => 'Technical review', 'role' => $this->leadEngineer, 'on_reject' => 'return_to_owner'],
            ['name' => 'Discipline check', 'role' => $this->leadEngineer, 'on_reject' => 'return_to_owner'],
            ['name' => 'Director endorsement', 'role' => $this->director, 'on_reject' => 'return_to_previous'],
        ]);

        $this->procedures->forceFill(['workflow_id' => $workflow->id])->save();

        $document = $this->procedure();
        $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->actingFresh($this->ngozi)->post(route('documents.submit', $document))->assertRedirect();

        $instance = $this->instanceFor($document);

        // Walk it to stage three.
        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $this->currentStage($instance)), ['decision' => 'approve'])
            ->assertRedirect();

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $this->currentStage($instance->refresh())), ['decision' => 'approve'])
            ->assertRedirect();

        $third = $this->currentStage($instance->refresh());
        $this->assertSame('Director endorsement', $third->stage_name);

        // The director sends it back one stage.
        $this->actingFresh($this->yusuf)
            ->post(route('approvals.decide', $third), [
                'decision' => 'reject',
                'comment' => 'The discipline check missed the temporary works loading.',
            ])
            ->assertRedirect();

        $reopened = $this->currentStage($instance->refresh());

        $this->assertSame('Discipline check', $reopened->stage_name, 'The wrong stage was reopened.');
    }

    // ================================================================== definitions are versioned

    #[Test]
    public function editing_a_published_route_does_not_change_what_is_already_travelling_it(): void
    {
        [$document] = $this->submittedDocument();
        $instance = $this->instanceFor($document);

        $before = $this->currentStage($instance)->stage_name;

        // The administrator revises the route: a new VERSION, superseding the old one.
        $workflow = $this->procedures->refresh()->workflow;

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.version', $workflow))
            ->assertRedirect();

        $successor = Workflow::withoutGlobalScopes()
            ->where('supersedes_id', $workflow->id)
            ->firstOrFail();

        $this->assertSame(2, (int) $successor->version);
        $this->assertNull($successor->published_at, 'A new version must start as a draft.');

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $successor), $this->stagePayload([
                'name' => 'Quality assurance',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->director->id,
            ]))
            ->assertRedirect();

        // The in-flight document is untouched: same stage, same name, same instance.
        $instance->refresh();

        $this->assertSame($before, $this->currentStage($instance)->stage_name);
        $this->assertSame((int) $workflow->id, (int) $instance->workflow_id);
    }

    #[Test]
    public function the_stage_name_on_a_decision_is_a_snapshot_not_a_lookup(): void
    {
        [$document] = $this->submittedDocument();
        $stage = $this->currentStage($this->instanceFor($document));

        $this->assertSame('Technical review', $stage->stage_name);

        // Somebody renames the stage on the definition afterwards. The history must still say what
        // the approver was actually asked to do — a history that renames itself is not a history.
        $this->actingFresh($this->adaeze)
            ->put(route('workflows.stages.update', [$stage->stage->workflow_id, $stage->workflow_stage_id]), $this->stagePayload([
                'name' => 'Renamed after the fact',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->leadEngineer->id,
            ]))
            ->assertRedirect();

        $this->assertSame('Technical review', $stage->refresh()->stage_name);
    }

    // ================================================================== publishing validates

    #[Test]
    public function a_route_with_no_stages_cannot_be_published(): void
    {
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Empty route',
                'entity_type' => 'document',
            ])
            ->assertRedirect();

        $workflow = Workflow::withoutGlobalScopes()->where('name', 'Empty route')->firstOrFail();

        $this->actingFresh($this->adaeze)
            ->from(route('workflows.show', $workflow))
            ->post(route('workflows.publish', $workflow))
            ->assertRedirect(route('workflows.show', $workflow))
            ->assertSessionHasErrors('publish');

        $this->assertNull($workflow->refresh()->published_at);
    }

    #[Test]
    public function a_stage_must_name_an_assignee_that_exists_in_this_tenant(): void
    {
        $other = $this->makeOrganization('Delta Marine');
        $foreignRole = $this->makeRole($other, 'Their Approver', ['approval.act' => 'organization']);

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), ['name' => 'Leaky route', 'entity_type' => 'document'])
            ->assertRedirect();

        $workflow = Workflow::withoutGlobalScopes()->where('name', 'Leaky route')->firstOrFail();

        // §75/§84: a posted id from another organization is not a valid reference here, and the
        // check is a rejection at the form rather than a route that resolves to a stranger.
        $this->actingFresh($this->adaeze)
            ->from(route('workflows.stages.create', $workflow))
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'Their review',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $foreignRole->id,
            ]))
            ->assertSessionHasErrors('assignee_ref');

        $this->assertSame(0, $workflow->stages()->count());
    }

    #[Test]
    public function a_role_stage_stores_the_role_id_and_resolves_real_people_from_it(): void
    {
        // Regression, and a subtle one: AssigneeResolver looks a role up by primary key. A builder
        // that stored the SLUG would save cleanly, show correctly on every screen, pass publishing,
        // and then resolve to nobody the first time a document actually travelled the route — an
        // approval that stalls in silence rather than failing.
        [$document] = $this->submittedDocument();

        $stage = $this->currentStage($this->instanceFor($document));

        $this->assertSame((string) $this->leadEngineer->id, (string) $stage->stage->assignee_ref);
        $this->assertNotSame($this->leadEngineer->slug, $stage->stage->assignee_ref);

        $this->assertContains(
            (int) $this->tunde->id,
            array_map('intval', $stage->resolved_assignees ?? []),
            'The role stage resolved to nobody. The reference is not being read as an id.',
        );
    }

    // ================================================================== assignees are frozen

    #[Test]
    public function who_must_approve_is_decided_when_the_stage_opens_not_when_it_is_read(): void
    {
        [$document] = $this->submittedDocument();
        $stage = $this->currentStage($this->instanceFor($document));

        $frozen = array_map('intval', $stage->resolved_assignees ?? []);
        $this->assertContains((int) $this->tunde->id, $frozen);

        // Somebody else joins the Lead Engineer role AFTER the stage opened. They are not on the
        // hook for this document: the list was settled when it entered the stage, which is what
        // makes "who was required to approve this" answerable a year later.
        $chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [$this->leadEngineer]);

        $stage->refresh();

        $this->assertSame($frozen, array_map('intval', $stage->resolved_assignees ?? []));
        $this->assertFalse($stage->isAssignedTo($chidi));

        $this->actingFresh($chidi)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertForbidden();
    }

    // ================================================================== signatures

    #[Test]
    public function approving_signs_the_revision_with_the_title_the_approver_held_at_the_time(): void
    {
        // The job title matters: an approval block that reads "Yusuf Danladi, Managing Director"
        // when he was a Design Manager on the day is a rewritten record, and it rewrites itself
        // silently the moment somebody is promoted.
        $designManager = $this->makePosition('Design Manager');

        $this->yusuf->employee->forceFill(['position_id' => $designManager->id])->save();

        $workflow = $this->buildRoute([
            ['name' => 'Technical review', 'role' => $this->leadEngineer],
            ['name' => 'Director endorsement', 'role' => $this->director],
        ]);

        $this->procedures->forceFill(['workflow_id' => $workflow->id])->save();

        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->actingFresh($this->ngozi)->post(route('documents.submit', $document))->assertRedirect();

        $instance = $this->instanceFor($document);
        $signed = $version->contentHash();

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $this->currentStage($instance)), ['decision' => 'approve'])
            ->assertRedirect();

        $this->actingFresh($this->yusuf)
            ->post(route('approvals.decide', $this->currentStage($instance->refresh())), ['decision' => 'approve'])
            ->assertRedirect();

        // TWO signatures, one per approver — the checked-by and the approved-by of an ordinary
        // title block, not a single stamp from whoever happened to finish the route.
        $signatures = Signature::withoutGlobalScopes()
            ->where('document_version_id', $version->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $signatures, 'Each approver signs; the route produced a different number.');

        $this->assertSame([(int) $this->tunde->id, (int) $this->yusuf->id],
            $signatures->pluck('user_id')->map('intval')->all());

        $endorsement = $signatures->last();

        $this->assertSame('Yusuf Danladi', $endorsement->name_snapshot);
        $this->assertSame('Design Manager', $endorsement->designation_snapshot);
        $this->assertSame('approve', $endorsement->action);
        $this->assertNotNull($endorsement->signed_at);
        $this->assertSame((int) $document->id, (int) $endorsement->signable_id);
        $this->assertSame('document', $endorsement->signable_type);

        // The hash is over the bytes that were APPROVED, taken before promotion renamed the
        // revision. If this drifts, "which revision did they sign" stops being answerable.
        $this->assertTrue($endorsement->matches($signed), 'The signature does not match the content it was taken over.');
        $this->assertTrue($endorsement->matches($version->refresh()->contentHash()));

        // Promoting the draft to revision A must not have disturbed either signature.
        $this->assertSame('A', $version->revision);
        $this->assertTrue($signatures->first()->matches($signed));

        // A promotion afterwards does not reach backwards.
        $this->yusuf->employee->forceFill(['position_id' => $this->makePosition('Managing Director')->id])->save();

        $this->assertSame('Design Manager', $endorsement->refresh()->designation_snapshot);
    }

    #[Test]
    public function a_refusal_is_recorded_as_a_decision_but_signs_nothing(): void
    {
        [$document, $version] = $this->submittedDocument();

        $this->actingFresh($this->tunde)
            ->post(route('approvals.decide', $this->currentStage($this->instanceFor($document))), [
                'decision' => 'reject',
                'comment' => 'The temporary works are not designed.',
            ])
            ->assertRedirect();

        // A signature is an affirmative endorsement. A table where some rows mean the opposite is
        // one somebody eventually counts, and the count is then wrong in the dangerous direction.
        $this->assertSame(0, Signature::withoutGlobalScopes()->where('document_version_id', $version->id)->count());

        // The decision itself is on the record, in full, where the author will read it.
        $this->actingFresh($this->ngozi)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('The temporary works are not designed.', false);
    }

    // ================================================================== registers that route nothing

    #[Test]
    public function a_register_that_requires_no_approval_issues_without_a_route(): void
    {
        // Regression. `requires_approval` was a checkbox the screens explained and nothing read:
        // finding no route on the category, the engine fell through to the tenant's DEFAULT
        // workflow and sent correspondence-received out for technical review. Every incoming letter
        // then sat unreadable in a reviewer's inbox, and the register page said, directly above it,
        // "Not required. Documents are issued by their author."
        $this->buildRoute(
            [['name' => 'Technical review', 'role' => $this->leadEngineer]],
            'Default document approval',
        );

        $correspondence = $this->makeDocumentCategory($this->org, 'Correspondence received', [
            'requires_approval' => false,
            'workflow_id' => null,
            'revision_scheme' => 'numeric',
            'draft_revision_prefix' => null,
        ]);

        $letter = $this->makeDocument($this->org, $this->ngozi, 'Client letter — jetty layout', [
            'document_category_id' => $correspondence->id,
            'org_unit_id' => $this->civil->id,
        ]);

        $version = $this->makeNativeVersion($this->org, $letter, $this->ngozi, '<p>We approve the layout.</p>');

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $letter))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($this->instanceFor($letter), 'An uncontrolled register invented an approval route.');
        $this->assertSame(0, Signature::withoutGlobalScopes()->where('document_version_id', $version->id)->count());

        // Issued outright, and the timeline says so — an unreviewed document must never be
        // indistinguishable from one somebody checked.
        $version->refresh();

        $this->assertNotNull($version->approved_at);
        $this->assertTrue((bool) $version->is_current);
        $this->assertSame((int) $version->id, (int) $letter->refresh()->current_version_id);
    }

    // ================================================================== fixture

    /** A job somebody holds. Titles live on positions, not on people — which is the point above. */
    private function makePosition(string $title): Position
    {
        return $this->withinOrganization($this->org, fn () => Position::create([
            'organization_id' => $this->org->id,
            'org_unit_id' => $this->engineering->id,
            'title' => $title,
            'is_active' => true,
        ]));
    }

    private function procedure(): Document
    {
        return $this->makeDocument($this->org, $this->ngozi, 'Excavation and shoring procedure', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
        ]);
    }

    /**
     * The route from the exit-criterion test, built once, plus a document already on stage 1.
     *
     * Built through the same HTTP endpoints rather than through the services, so every test in this
     * file is exercising a configuration an administrator could actually have produced.
     *
     * @return array{0: Document, 1: DocumentVersion}
     */
    private function submittedDocument(): array
    {
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Procedure approval',
                'entity_type' => 'document',
                'is_default' => '1',
            ])->assertRedirect();

        $workflow = Workflow::withoutGlobalScopes()->where('name', 'Procedure approval')->firstOrFail();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'Technical review',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->leadEngineer->id,
            ]))->assertRedirect();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))->assertRedirect();

        $this->procedures->forceFill(['workflow_id' => $workflow->id])->save();

        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))->assertRedirect();

        return [$document->refresh(), $version->refresh()];
    }

    /**
     * Build and publish a route through the UI endpoints.
     *
     * @param  array<int,array{name:string,role:Role,on_reject?:string}>  $stages
     */
    private function buildRoute(array $stages, string $name = 'Procedure approval'): Workflow
    {
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => $name,
                'entity_type' => 'document',
                'is_default' => '1',
            ])->assertRedirect();

        $workflow = Workflow::withoutGlobalScopes()->where('name', $name)->firstOrFail();

        foreach ($stages as $stage) {
            $this->actingFresh($this->adaeze)
                ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                    'name' => $stage['name'],
                    'assignee_type' => 'role',
                    'assignee_ref' => (string) $stage['role']->id,
                    'on_reject' => $stage['on_reject'] ?? 'return_to_owner',
                ]))->assertSessionHasNoErrors();
        }

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))
            ->assertSessionHasNoErrors();

        return $workflow->refresh();
    }

    /**
     * A stage form's defaults, so each test states only what it is actually about.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function stagePayload(array $overrides): array
    {
        return [
            'action_type' => 'approve',
            'quorum' => 'all',
            'on_reject' => 'return_to_owner',
            'allow_delegate' => '1',
            'allow_self_approval' => '0',
            'is_active' => '1',
            ...$overrides,
        ];
    }

    private function instanceFor(Document $document): ?WorkflowInstance
    {
        return WorkflowInstance::withoutGlobalScopes()
            ->where('subject_type', 'document')
            ->where('subject_id', $document->getKey())
            ->whereNull('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The stage the record is actually sitting on.
     *
     * Read from `current_stage_instance_id` rather than inferred from statuses, because that column
     * IS the engine's answer to the question — inferring it in the test would mean the test could
     * agree with itself while disagreeing with the engine.
     */
    private function currentStage(WorkflowInstance $instance): WorkflowStageInstance
    {
        return WorkflowStageInstance::withoutGlobalScopes()
            ->whereKey($instance->current_stage_instance_id)
            ->firstOrFail();
    }
}
