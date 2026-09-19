<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Configuration\SettingsRepository;
use App\Engines\Organization\OrganizationProvisioner;
use App\Engines\Workflow\EscalationService;
use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageInstance;
use App\Services\FormDefinitionService;
use App\Services\WorkflowDefinitionService;
use App\Support\SettingsCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Sending a filled-in form out for approval (Phase 7, Part 4).
 *
 * WHAT THIS FILE IS DEFENDING:
 *
 *   THERE IS ONE WORKFLOW ENGINE (§78). A form submission is routed by the same engine, through the
 *   same stages, into the same approval inbox as a document or a purchase request. The temptation
 *   in a form builder is always to grow a second, simpler approval — "just send it to this person"
 *   — and the second one is invariably the one without delegation, without escalation and without
 *   an audit trail. Every test here goes through `approvals.decide`, which is the proof.
 *
 *   WHOEVER FILLS A SHEET IN DOES NOT CHOOSE WHO APPROVES IT. The binding is on the FORM. There is
 *   no field on a submission that names a route, and the tests below post one to make sure.
 *
 *   `requires_approval` AND `workflow_id` ARE DIFFERENT QUESTIONS. Whether, and which. A form that
 *   says no approval is not routed even where the company has a default route for form
 *   submissions, because a screen saying "no approval needed" must not describe a system that is
 *   quietly asking somebody anyway.
 *
 *   A POSTED ROUTE ID IS NOT AUTHORITY (§74, §75, §84). A workflow belonging to another company, or
 *   built to approve documents, does not bind however plausibly it is posted — and it fails by
 *   binding nothing rather than by binding the wrong thing.
 *
 *   A ROUTE THAT COULD NOT START SAYS SO. A form that asks for approval on a company with no route
 *   for form submissions leaves the sheet submitted and writes the gap onto the record, because
 *   the alternative is a permit sitting in nobody's inbox looking exactly like one that is simply
 *   taking a while (§73).
 *
 *   A SETTING ON A SCREEN CHANGES SOMETHING. The last section covers the approval settings an
 *   administrator can see: that every key a new company is seeded with is one of them, and that the
 *   two the escalation ladder reads are genuinely read. A switch that does nothing is worse than no
 *   switch, because somebody sets it and then stops watching the thing it claimed to control.
 */
final class FormApprovalTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    /** Builds the forms and the routes they travel. */
    private User $admin;

    /** Fills sheets in and sends them. */
    private User $filler;

    /** Sits on the approving stage. */
    private User $approver;

    private Role $approvers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->provisionConfiguration($this->org);

        $this->admin = $this->makePerson($this->org, 'Adaeze Okonkwo', null, [
            $this->makeRole($this->org, 'Process administrator', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
                'form.publish' => 'organization',
                'form.review' => 'organization',
                'form.manage' => 'organization',
                'workflow.view' => 'organization',
                'workflow.create' => 'organization',
                'workflow.edit' => 'organization',
                'workflow.manage' => 'organization',
                'workflow.publish' => 'organization',
            ]),
        ]);

        $this->filler = $this->makePerson($this->org, 'Tunde Balogun', null, [
            $this->makeRole($this->org, 'Site technician', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
                'form.submit' => 'organization',
            ]),
        ]);

        // The approver can READ what they are being asked to approve. §68 in the permissions
        // rather than in a later patch: an inbox entry nobody may open is a dead end.
        $this->approvers = $this->makeRole($this->org, 'Permit authority', [
            'form.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
            'approval.delegate' => 'organization',
        ]);

        $this->approver = $this->makePerson($this->org, 'Ngozi Eze', null, [$this->approvers]);
    }

    // ================================================================== whether it routes at all

    #[Test]
    public function a_form_that_asks_for_no_approval_sends_its_sheets_nowhere(): void
    {
        // A default route EXISTS for filled-in forms, and is still not used. That is the whole
        // distinction between "which route" and "whether at all".
        $this->approvalRoute();

        $submission = $this->submitted($this->formRequiring(false));

        $this->assertNull($this->instanceFor($submission),
            'a form that asks for no approval must not fall through to the company default');
        $this->assertSame('submitted', $this->refresh($submission)->statusKey());
    }

    #[Test]
    public function a_form_that_asks_for_approval_puts_its_sheets_in_somebody_s_inbox(): void
    {
        $route = $this->approvalRoute();

        $submission = $this->submitted($this->formRequiring(true, $route));

        $instance = $this->instanceFor($submission);

        $this->assertNotNull($instance);
        $this->assertSame(WorkflowInstance::STATUS_RUNNING, $instance->status);
        $this->assertSame($route->getKey(), $instance->workflow_id);

        // And it is the ORDINARY inbox, not a form-shaped one beside it (§78).
        $this->actingFresh($this->approver)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee($submission->displayName());
    }

    #[Test]
    public function a_sheet_out_for_approval_names_its_route_on_the_record_screen(): void
    {
        $route = $this->approvalRoute('Permit authorisation');
        $submission = $this->submitted($this->formRequiring(true, $route));

        $this->actingFresh($this->admin)
            ->get(route('form-submissions.show', $submission))
            ->assertOk()
            ->assertSee('Permit authorisation')
            ->assertSee('Gate keeper');
    }

    // ================================================================== what the route decides

    #[Test]
    public function approving_the_last_stage_accepts_the_sheet(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));

        $this->decide($submission, 'approve');

        $this->assertSame('accepted', $this->refresh($submission)->statusKey());
        $this->assertSame(WorkflowInstance::STATUS_COMPLETED,
            $this->instanceFor($submission)->status);
    }

    #[Test]
    public function rejecting_on_a_route_that_ends_there_rejects_the_sheet(): void
    {
        $route = $this->approvalRoute('Permit authorisation', WorkflowStage::ON_REJECT_TERMINATE);
        $submission = $this->submitted($this->formRequiring(true, $route));

        $this->decide($submission, 'reject', 'The gas readings are from yesterday.');

        $this->assertSame('rejected', $this->refresh($submission)->statusKey());
    }

    #[Test]
    public function rejecting_on_a_route_that_sends_it_back_hands_the_sheet_over_instead(): void
    {
        // WHAT A NO MEANS IS THE ROUTE'S DECISION, NOT THIS MODULE'S (§23). The same click on a
        // stage configured to return to the owner puts the sheet back in the author's hands rather
        // than killing it — and the register's status has to follow the route, not second-guess it.
        $route = $this->approvalRoute('Permit authorisation', WorkflowStage::ON_REJECT_OWNER);
        $submission = $this->submitted($this->formRequiring(true, $route));

        $this->decide($submission, 'reject', 'The gas readings are from yesterday.');

        $this->assertSame('returned', $this->refresh($submission)->statusKey());
    }

    #[Test]
    public function asking_for_changes_hands_the_sheet_back_to_be_edited(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));

        $this->decide($submission, 'request_revision', 'Name the standby man.');

        $fresh = $this->refresh($submission);

        $this->assertSame('returned', $fresh->statusKey());

        // "Returned" is not decoration: the sheet is editable again, which is the entire reason
        // the route's revision outcome maps onto this status rather than onto `draft`.
        $this->actingFresh($this->filler)
            ->get(route('form-submissions.edit', $fresh))
            ->assertOk();
    }

    #[Test]
    public function the_record_says_the_route_decided_and_which_route_it_was(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));

        $this->decide($submission, 'approve');

        $entry = $this->activityOn($submission, 'approval_accepted');

        $this->assertNotNull($entry, 'an approval that moves a record must say so on the record');
        $this->assertSame($this->instanceFor($submission)->getKey(),
            $entry->properties['workflow_instance_id'] ?? null);
        // Attributed to whoever actually decided, not to the system and not to the submitter.
        $this->assertSame($this->approver->getKey(), $entry->causer_id);
        $this->assertSame('Ngozi Eze', $entry->causer_name);
    }

    #[Test]
    public function a_sheet_sent_back_and_sent_again_travels_one_approval_not_two(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));

        $this->decide($submission, 'request_revision', 'Name the standby man.');

        $this->actingFresh($this->filler)
            ->post(route('form-submissions.submit', $this->refresh($submission)))
            ->assertSessionHasNoErrors();

        $instances = $this->instancesFor($submission);

        // ONE instance, on its second attempt. Two would give the same permit two approval
        // histories, and the trail on the record screen would show whichever it happened to load.
        $this->assertCount(1, $instances);
        $this->assertSame(2, (int) $instances->sole()->attempt);
        $this->assertSame(WorkflowInstance::STATUS_RUNNING, $instances->sole()->status);
    }

    // ================================================================== when it cannot route

    #[Test]
    public function a_form_that_needs_approval_with_no_route_built_still_keeps_the_sheet(): void
    {
        $submission = $this->submitted($this->formRequiring(true));

        $fresh = $this->refresh($submission);

        // The sheet is SUBMITTED. Routing happens after the submit commits precisely so that a
        // configuration gap cannot swallow somebody's work.
        $this->assertTrue($fresh->isSubmitted());
        $this->assertSame('submitted', $fresh->statusKey());
        $this->assertNull($this->instanceFor($submission));

        $this->assertNotNull($this->activityOn($submission, 'approval_not_started'),
            'a route that could not start must be written onto the record, not swallowed');
    }

    #[Test]
    public function the_record_screen_says_plainly_that_the_sheet_went_nowhere(): void
    {
        $submission = $this->submitted($this->formRequiring(true));

        $this->actingFresh($this->admin)
            ->get(route('form-submissions.show', $submission))
            ->assertOk()
            ->assertSee('Not routed')
            ->assertSee('there is no approval route set up for filled-in forms');
    }

    // ================================================================== the binding itself

    #[Test]
    public function the_person_filling_a_sheet_in_cannot_choose_who_approves_it(): void
    {
        $route = $this->approvalRoute();
        $form = $this->formRequiring(false);

        // Posted at the endpoint that starts a sheet, in every shape somebody might try.
        $this->actingFresh($this->filler)
            ->post(route('form-submissions.store'), [
                'form_id' => $form->getKey(),
                'workflow_id' => $route->getKey(),
                'requires_approval' => true,
            ])
            ->assertRedirect();

        $submission = $this->latestSubmission();

        $this->assertNull($this->instanceFor($submission));
        $this->assertNull($submission->getAttribute('workflow_id'),
            'a submission must not carry a route of its own');
    }

    #[Test]
    public function another_company_s_approval_route_does_not_bind(): void
    {
        $other = $this->makeOrganization('Kano Fabrication');
        $this->provisionConfiguration($other);

        $stranger = $this->makePerson($other, 'Hauwa Sani', null, [
            $this->makeRole($other, 'Administrator', [
                'workflow.view' => 'organization',
                'workflow.create' => 'organization',
                'workflow.manage' => 'organization',
                'workflow.publish' => 'organization',
            ]),
        ]);

        $theirs = $this->makeWorkflow($other, $stranger, 'Their route', 'form_submission', [[
            'name' => 'Gate keeper',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $stranger->getKey(),
        ]]);

        $form = $this->formRequiring(false);

        $this->actingFresh($this->admin)
            ->put(route('forms.update', $form), [
                'name' => $form->name,
                'key' => $form->key,
                'requires_approval' => '1',
                'workflow_id' => $theirs->getKey(),
            ])
            ->assertSessionHasNoErrors();

        // Bound to NOTHING rather than to theirs. The form now falls through to this company's own
        // default, which is a visible configuration gap; binding their route would have been an
        // invisible one that leaks a permit into another company's inbox (§75).
        $this->assertNull($this->freshForm($form)->workflow_id);
    }

    #[Test]
    public function a_route_built_to_approve_documents_cannot_be_nominated_by_a_form(): void
    {
        $documents = $this->makeWorkflow($this->org, $this->admin, 'Drawing review', 'document', [[
            'name' => 'Checker',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $this->approver->getKey(),
        ]]);

        $form = $this->formRequiring(false);

        $this->actingFresh($this->admin)
            ->put(route('forms.update', $form), [
                'name' => $form->name,
                'key' => $form->key,
                'requires_approval' => '1',
                'workflow_id' => $documents->getKey(),
            ])
            ->assertSessionHasNoErrors();

        // A route pointed at the wrong module does not fail loudly — its conditions match nothing,
        // its stages skip, and the sheet is approved by an empty route. Refusing the binding turns
        // that into a blank field somebody can see.
        $this->assertNull($this->freshForm($form)->workflow_id);
    }

    #[Test]
    public function the_builder_offers_the_routes_a_form_may_actually_take(): void
    {
        $this->approvalRoute('Permit authorisation');

        $unpublished = $this->withinOrganization($this->org,
            fn () => app(WorkflowDefinitionService::class)->create([
                'name' => 'Half-built route',
                'entity_type' => 'form_submission',
            ], $this->admin));

        $this->makeWorkflow($this->org, $this->admin, 'Drawing review', 'document', [[
            'name' => 'Checker',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $this->approver->getKey(),
        ]]);

        $this->actingFresh($this->admin)
            ->get(route('forms.show', $this->formRequiring(false)))
            ->assertOk()
            ->assertSee('Permit authorisation')
            // A draft route has stages nobody has agreed to; a document route approves the wrong
            // kind of thing. Neither is offered, and neither would bind if it were posted anyway.
            ->assertDontSee($unpublished->name)
            ->assertDontSee('Drawing review');
    }

    #[Test]
    public function a_form_keeps_its_route_when_nothing_on_the_screen_says_otherwise(): void
    {
        $route = $this->approvalRoute();
        $form = $this->formRequiring(true, $route);

        // A rename posted from a screen that never showed the approval fields must not quietly
        // unbind the route — which is what a blanket "absent means null" would do.
        $this->actingFresh($this->admin)
            ->put(route('forms.update', $form), [
                'name' => 'Hot work permit',
                'key' => $form->key,
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->freshForm($form);

        $this->assertSame('Hot work permit', $fresh->name);
        $this->assertSame($route->getKey(), $fresh->workflow_id);
        $this->assertTrue($fresh->requires_approval);
    }

    // ================================================================== the company-wide veto

    #[Test]
    public function a_company_that_does_not_delegate_does_not_delegate(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));

        $stage = $this->currentStage($submission);
        $deputy = $this->makePerson($this->org, 'Chidi Nwosu', null, [$this->approvers]);

        // The STAGE allows it — makeWorkflow sets allow_delegate — and the company has said no.
        $this->setting('workflow.allow_delegation', false);

        $this->actingFresh($this->approver)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'delegate',
                'delegate_to' => $deputy->getKey(),
                'comment' => 'I am on leave.',
            ])
            ->assertForbidden();

        $this->setting('workflow.allow_delegation', true);

        $this->actingFresh($this->approver)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'delegate',
                'delegate_to' => $deputy->getKey(),
                'comment' => 'I am on leave.',
            ])
            ->assertSessionHasNoErrors();
    }

    // ================================================================== the settings that used to lie

    #[Test]
    public function every_setting_a_new_company_is_given_can_be_found_and_changed(): void
    {
        // Seeded here rather than in setUp: the test fixtures provision statuses, numbering and
        // option lists but not settings, and what is under test is precisely the list a real new
        // company is given by OrganizationProvisioner::seedSettings().
        $seeded = $this->withinOrganization($this->org, function () {
            app(OrganizationProvisioner::class)->seedSettings($this->org);

            return Setting::query()
                ->where('organization_id', $this->org->getKey())
                ->pluck('key')
                ->all();
        });

        $offered = array_keys(SettingsCatalogue::definitions());

        // Guard against the vacuous green: a provisioner that seeded nothing would satisfy the
        // assertion below perfectly.
        $this->assertNotEmpty($seeded);
        $this->assertNotEmpty($offered);

        // THE RULE THE CATALOGUE'S OWN DOCBLOCK STATES, made enforceable. A key seeded into every
        // new company but absent from the catalogue is a value nobody can see, nobody can change
        // and nobody can discover is wrong — which is how five dead approval settings survived
        // several phases looking exactly like working ones.
        $this->assertSame([], array_values(array_diff($seeded, $offered)),
            'these settings are given to every new company but appear on no screen');
    }

    #[Test]
    public function the_ladder_climbs_no_further_than_the_company_allowed(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));
        $stage = $this->overdueSince($this->currentStage($submission), 30 * 24);

        $this->setting('workflow.escalation_max_level', 1);

        // Three passes, and every level of the ladder is long since eligible. The cap is the only
        // thing that can stop it — which is the claim, since nothing read this key before.
        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, (int) $this->freshStage($stage)->last_escalated_level,
            'a company that chases only its approvers must not have their supervisors told');
    }

    #[Test]
    public function the_gap_between_chases_is_the_company_s_to_set(): void
    {
        $submission = $this->submitted($this->formRequiring(true, $this->approvalRoute()));
        $stage = $this->overdueSince($this->currentStage($submission), 3);

        $this->setting('workflow.escalation_step_hours', 48);

        $this->sweep();
        $this->assertSame(1, (int) $this->freshStage($stage)->last_escalated_level);

        // Three hours late, and the company asked for two days between chases. The supervisor is
        // not told yet, however many times the nightly sweep runs.
        $this->sweep();
        $this->assertSame(1, (int) $this->freshStage($stage)->last_escalated_level);

        $this->setting('workflow.escalation_step_hours', 1);

        $this->sweep();
        $this->assertSame(2, (int) $this->freshStage($stage)->last_escalated_level);
    }

    // ================================================================== helpers

    /**
     * A published, single-stage route for filled-in forms.
     *
     * The stage names the approving ROLE rather than a person, which is the shape a company
     * actually configures — "the permit authority approves it", not "Ngozi approves it".
     */
    private function approvalRoute(
        string $name = 'Form approval',
        string $onReject = WorkflowStage::ON_REJECT_OWNER,
    ): Workflow {
        return $this->makeWorkflow($this->org, $this->admin, $name, 'form_submission', [[
            'name' => 'Gate keeper',
            'sequence' => 1,
            'assignee_type' => 'role',
            'assignee_ref' => (string) $this->approvers->getKey(),
            'on_reject' => $onReject,
        ]]);
    }

    /** A published one-question form, bound as asked. */
    private function formRequiring(bool $approval, ?Workflow $route = null): Form
    {
        return $this->withinOrganization($this->org, function () use ($approval, $route) {
            $forms = app(FormDefinitionService::class);

            $form = $forms->createForm([
                'name' => 'Work permit',
                'key' => 'work_permit',
                'category' => 'HSE',
                'requires_approval' => $approval,
                'workflow_id' => $route?->getKey(),
            ], $this->admin);

            $version = $form->versions()->first();

            $forms->addField($version->sections()->first(), [
                'label' => 'Permit holder', 'key' => 'holder', 'type' => 'text',
                'is_required' => true,
            ], $this->admin);

            $forms->publish($version, $this->admin);

            return $form->refresh();
        });
    }

    /** Fill one in and send it, through the endpoints a person would use. */
    private function submitted(Form $form): FormSubmission
    {
        $this->actingFresh($this->filler)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect();

        $submission = $this->latestSubmission();

        $this->actingFresh($this->filler)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['holder' => 'Ngozi Eze'],
            ])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->filler)
            ->post(route('form-submissions.submit', $submission))
            ->assertSessionHasNoErrors();

        return $this->refresh($submission);
    }

    /** Act on whatever stage the sheet is currently waiting at. */
    private function decide(FormSubmission $submission, string $decision, ?string $comment = null): void
    {
        $this->actingFresh($this->approver)
            ->post(route('approvals.decide', $this->currentStage($submission)),
                array_filter(['decision' => $decision, 'comment' => $comment]))
            ->assertSessionHasNoErrors();
    }

    private function currentStage(FormSubmission $submission): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->where('workflow_instance_id', $this->instanceFor($submission)?->getKey())
            ->where('status', WorkflowStageInstance::STATUS_PENDING)
            ->orderBy('sequence')
            ->firstOrFail());
    }

    private function instanceFor(FormSubmission $submission): ?WorkflowInstance
    {
        return $this->instancesFor($submission)->last();
    }

    /**
     * Every instance ever raised against this sheet.
     *
     * Queried on `subject_type`/`subject_id` — the morph columns the engine actually writes, with
     * the morph ALIAS in the type column. A helper that guessed at the column names would have
     * reported "nothing was routed" for a sheet that was, which is the shape of green test that
     * proves nothing.
     *
     * @return Collection<int,WorkflowInstance>
     */
    private function instancesFor(FormSubmission $submission): Collection
    {
        return $this->withinOrganization($this->org, fn () => WorkflowInstance::query()
            ->where('subject_type', $submission->getMorphClass())
            ->where('subject_id', $submission->getKey())
            ->orderBy('id')
            ->get());
    }

    private function activityOn(FormSubmission $submission, string $verb): ?ActivityLog
    {
        return $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', $submission->getMorphClass())
            ->where('subject_id', $submission->getKey())
            ->where('verb', $verb)
            ->latest('id')
            ->first());
    }

    private function latestSubmission(): FormSubmission
    {
        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->orderByDesc('id')->firstOrFail());
    }

    private function refresh(FormSubmission $submission): FormSubmission
    {
        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->whereKey($submission->getKey())->firstOrFail());
    }

    private function freshForm(Form $form): Form
    {
        return $this->withinOrganization($this->org,
            fn () => Form::query()->whereKey($form->getKey())->firstOrFail());
    }

    /**
     * Put a pending stage's deadline into the past and hand it back.
     *
     * `forceFill`, because `due_at` is the engine's to write and is not fillable — which is right
     * everywhere except here, where the alternative is a test that waits a real day.
     */
    private function overdueSince(WorkflowStageInstance $stage, int $hoursAgo): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, function () use ($stage, $hoursAgo) {
            $stage->forceFill(['due_at' => now()->subHours($hoursAgo)])->save();

            return $stage;
        });
    }

    /** One nightly sweep, for this company only. */
    private function sweep(): int
    {
        return $this->withinOrganization($this->org,
            fn () => app(EscalationService::class)->sweepCurrentTenant());
    }

    private function freshStage(WorkflowStageInstance $stage): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->whereKey($stage->getKey())
            ->firstOrFail());
    }

    private function setting(string $key, mixed $value): void
    {
        $this->withinOrganization($this->org, function () use ($key, $value) {
            app(SettingsRepository::class)->set($key, $value, $this->org->getKey());
        });
    }
}
