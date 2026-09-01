<?php

declare(strict_types=1);

namespace Tests\Feature\Hse;

use App\Engines\Audit\ActivityRecorder;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
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
 * PHASE 4'S EXIT CRITERION, stated in docs/14-roadmap.md as:
 *
 *     "an HSE incident automatically spawns an investigation and corrective-action task through
 *      configuration alone."
 *
 * THE WORDS THAT MATTER ARE "THROUGH CONFIGURATION ALONE". The claim is not that the application
 * can open an investigation — a controller action does that, and there is one. It is that a safety
 * manager who has never seen the source code can say "when an incident goes for review, open an
 * investigation and raise a corrective action" on a form, and it then happens to real incidents.
 *
 * So the route below is built by POSTing to the same three endpoints the workflow builder screen
 * posts to, with the same field names its inputs carry. Nothing here calls WorkflowDefinitionService,
 * constructs a WorkflowStage, or writes `auto_actions` into the database. If any part of the
 * configuration could only be expressed from code, this test would not compile — which is exactly
 * the failure that should be loud, because it is the difference between a configurable platform and
 * a platform with a configuration screen.
 *
 * IT ALREADY CAUGHT TWO. Written against the code as it stood, this test failed twice for reasons
 * no unit test would have found:
 *
 *   1. WorkflowStageRequest validated every auto-action key EXCEPT `record`, and `validated()`
 *      returns only what the rules name. The stage saved, the screen said it saved, the builder
 *      showed the action — and the runner then threw "a create_record action named no record type"
 *      on the first incident that reached the stage. Configuration that silently discards half of
 *      itself is worse than configuration that refuses.
 *
 *   2. Nothing outside DocumentService ever called WorkflowEngine::start(). The engine was general
 *      and its one caller was not, so no register record could enter a route at all — the exit
 *      criterion was unreachable through the UI no matter how the workflow was configured (§90).
 *      RegisterController::submitForApproval() is the answer, and this is the test that demanded it.
 *
 * IDEMPOTENCE IS TESTED SEPARATELY AND IS NOT A DETAIL. A stage is re-entered every time a report
 * is sent back and resubmitted, and each entry runs its auto-actions again. An incident that went
 * round twice must not end up with two investigations, and the check for that lives in
 * `Investigation::spawnFrom()` — where the fact "one per incident" actually belongs — rather than
 * in the engine, which knows nothing about investigations.
 */
final class IncidentSpawnsInvestigationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $operations;

    private OrgUnit $site;

    private Project $refinery;

    private Role $hseManager;

    private User $adaeze;   // process administrator: configures the route

    private User $chidi;    // site safety officer: reports the incident

    private User $fatima;   // HSE manager: the stage the route opens

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Kaduna Fabrication');
        $this->operations = $this->makeUnit($this->org, 'Operations', OrgUnitType::Division);
        $this->site = $this->makeUnit($this->org, 'Yard', OrgUnitType::Department, $this->operations);

        // The approver holds `hse_incident.view` as well as the approval abilities, because §68
        // applies to an approval inbox exactly as it applies to a register: nobody is asked to
        // decide on a record they are not cleared to read.
        $this->hseManager = $this->makeRole($this->org, 'HSE Manager', [
            'hse_incident.view' => 'organization',
            'hse_incident.investigate' => 'organization',
            'corrective_action.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
        ]);

        $administrator = $this->makeRole($this->org, 'Process Administrator', [
            'workflow.view' => 'organization',
            'workflow.create' => 'organization',
            'workflow.edit' => 'organization',
            'workflow.manage' => 'organization',
            'workflow.publish' => 'organization',
            'role.view' => 'organization',
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        $safetyOfficer = $this->makeRole($this->org, 'Site Safety Officer', [
            'hse_incident.view' => 'organization',
            'hse_incident.create' => 'organization',
            'hse_incident.edit' => 'organization',
            'hse_incident.investigate' => 'organization',
            'corrective_action.view' => 'organization',
            'project.view' => 'organization',
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Obi', $this->operations, [$administrator]);
        $this->chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->site, [$safetyOfficer]);
        $this->fatima = $this->makePerson($this->org, 'Fatima Bello', $this->operations, [$this->hseManager]);

        $this->refinery = $this->makeProject($this->org, $this->adaeze, 'Refinery Turnaround', [
            'org_unit_id' => $this->operations->id,
        ]);
    }

    // ================================================================== the exit criterion

    #[Test]
    public function an_incident_spawns_an_investigation_and_a_corrective_action_through_configuration_alone(): void
    {
        $workflow = $this->configureTheRouteThroughTheUi();

        // ---- The incident. Reported by a site officer through the ordinary register form. ------
        $incident = $this->reportIncident();

        $this->assertNull(
            $incident->investigation()->first(),
            'Reporting an incident opened an investigation on its own — that would make the workflow irrelevant.',
        );

        // ---- Submitting it is what starts the route. -------------------------------------------
        $this->actingFresh($this->chidi)
            ->from(route('incidents.show', $incident))
            ->post(route('incidents.submit', $incident))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $instance = $this->instanceFor($incident);
        $this->assertNotNull($instance, 'Submitting produced no workflow instance: the route was not picked up.');
        $this->assertSame((int) $workflow->id, (int) $instance->workflow_id);

        $stage = $this->currentStage($instance);
        $this->assertSame('HSE manager review', $stage->stage_name);
        $this->assertTrue(
            $stage->isAssignedTo($this->fatima),
            'The stage resolved to somebody other than the role the administrator chose.',
        );

        // ---- The investigation opened itself. --------------------------------------------------
        $investigation = Investigation::withoutGlobalScopes()
            ->where('incident_id', $incident->getKey())
            ->first();

        $this->assertNotNull($investigation, 'No investigation was opened. This is the exit criterion.');
        $this->assertNotEmpty($investigation->number, 'The investigation was opened without a register number.');
        $this->assertSame(
            (int) $this->fatima->id,
            (int) $investigation->lead_id,
            'The lead was not resolved from the role the auto-action named.',
        );

        // Context carried across from the incident, because an investigation filed against no
        // project is invisible to everybody scoped to one — which is most of the people who need it.
        $this->assertSame((int) $this->refinery->id, (int) $investigation->project_id);
        $this->assertSame(
            $incident->confidentiality->value,
            $investigation->confidentiality->value,
            'The investigation is classified more openly than the incident it is about (§68).',
        );
        $this->assertSame(
            now()->addDays(14)->toDateString(),
            $investigation->due_on->toDateString(),
            'The configured `due_in_days` did not survive the form.',
        );

        // ---- And so did the corrective action. -------------------------------------------------
        $action = CorrectiveAction::withoutGlobalScopes()
            ->where('source_type', 'hse_incident')
            ->where('source_id', $incident->getKey())
            ->first();

        $this->assertNotNull($action, 'No corrective action was raised.');
        $this->assertSame('Make the scaffold platform safe', $action->title);
        $this->assertSame(
            (int) $this->chidi->id,
            (int) $action->assigned_to,
            'The action was not assigned to the user the auto-action named.',
        );
        $this->assertSame(now()->addDays(7)->toDateString(), $action->due_on->toDateString());

        // ---- Both are on the incident's own timeline, where somebody will actually read them. ---
        $narratives = app(ActivityRecorder::class)->timelineFor($incident)->pluck('description')->implode(' | ');

        $this->assertStringContainsString($investigation->number, $narratives);
        $this->assertStringContainsString($action->number, $narratives);

        // ---- And on its page. ------------------------------------------------------------------
        $this->actingFresh($this->chidi)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee($investigation->number, false)
            ->assertSee('Make the scaffold platform safe', false);
    }

    // ================================================================== idempotence

    #[Test]
    public function an_incident_that_goes_round_the_route_twice_collects_one_investigation(): void
    {
        $this->configureTheRouteThroughTheUi();

        $incident = $this->reportIncident();

        $this->actingFresh($this->chidi)->post(route('incidents.submit', $incident))->assertRedirect();

        $first = Investigation::withoutGlobalScopes()->where('incident_id', $incident->getKey())->firstOrFail();

        // Sent back to the author, which closes the instance and returns the incident to its owner.
        $stage = $this->currentStage($this->instanceFor($incident));

        $this->actingFresh($this->fatima)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'request_revision',
                'comment' => 'The scaffold tag numbers are missing. Please add them and resubmit.',
            ])
            ->assertRedirect();

        $this->assertFalse($incident->refresh()->isInWorkflow());

        // Round again. The stage re-opens and its auto-actions run a second time.
        $this->actingFresh($this->chidi)
            ->post(route('incidents.submit', $incident))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->instanceFor($incident), 'The second submission did not start a route.');

        $this->assertSame(
            1,
            Investigation::withoutGlobalScopes()->where('incident_id', $incident->getKey())->count(),
            'Going round twice collected two investigations.',
        );

        $this->assertSame(
            (int) $first->id,
            (int) Investigation::withoutGlobalScopes()->where('incident_id', $incident->getKey())->value('id'),
            'The second pass replaced the investigation rather than leaving it alone.',
        );

        $this->assertSame(
            1,
            CorrectiveAction::withoutGlobalScopes()
                ->where('source_type', 'hse_incident')
                ->where('source_id', $incident->getKey())
                ->count(),
            'Going round twice raised the same corrective action twice.',
        );
    }

    // ================================================================== the configuration itself

    #[Test]
    public function a_stage_cannot_be_configured_to_open_a_record_type_that_does_not_exist(): void
    {
        $workflow = $this->makeDraftRoute();

        $this->actingFresh($this->adaeze)
            ->from(route('workflows.stages.create', $workflow))
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'HSE manager review',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->hseManager->id,
                'auto_actions' => [
                    ['verb' => 'create_record', 'record' => 'user', 'title' => 'Open a person'],
                ],
            ]))
            ->assertSessionHasErrors('auto_actions.0.record');

        $this->assertSame(0, $workflow->stages()->count(), 'The stage saved despite naming a record type nothing can open.');
    }

    #[Test]
    public function the_configured_record_type_survives_the_round_trip_through_the_form(): void
    {
        // The regression this file was written for: `validated()` returns only what the rules name,
        // so an unvalidated `record` key was stripped between the builder and the stage. The stage
        // saved, the screen said it saved, and the action failed weeks later on a live incident.
        $workflow = $this->makeDraftRoute();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'HSE manager review',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->hseManager->id,
                'auto_actions' => [
                    ['verb' => 'create_record', 'record' => 'investigation', 'due_in_days' => '14'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $stage = $workflow->stages()->firstOrFail();

        $this->assertSame('investigation', $stage->auto_actions[0]['record'] ?? null);

        // And the builder shows back what was saved, rather than a stage that reads as doing nothing.
        $this->actingFresh($this->adaeze)
            ->get(route('workflows.show', $workflow))
            ->assertOk()
            ->assertSee('Investigation', false);
    }

    // ================================================================== helpers

    /**
     * Everything an administrator does, done the way an administrator does it.
     *
     * Three POSTs to three endpoints, with the payloads the builder's own inputs produce. This is
     * the whole claim being tested; every assertion above is downstream of it.
     */
    private function configureTheRouteThroughTheUi(): Workflow
    {
        $workflow = $this->makeDraftRoute();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), $this->stagePayload([
                'name' => 'HSE manager review',
                'instructions' => 'Confirm the account, then decide whether it is reportable.',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->hseManager->id,
                'sla_hours' => '24',
                'auto_actions' => [
                    // "Open an investigation, led by whoever the HSE manager role resolves to,
                    // due in a fortnight." One sentence on a form.
                    [
                        'verb' => 'create_record',
                        'record' => 'investigation',
                        'title' => 'Investigation into the reported incident',
                        'due_in_days' => '14',
                        'assignee_type' => 'role',
                        'assignee_ref' => (string) $this->hseManager->id,
                    ],
                    [
                        'verb' => 'create_record',
                        'record' => 'corrective_action',
                        'title' => 'Make the scaffold platform safe',
                        'due_in_days' => '7',
                        'assignee_type' => 'user',
                        'assignee_ref' => (string) $this->chidi->id,
                    ],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $workflow->refresh();

        $this->assertNotNull($workflow->published_at, 'Publishing did not take, so nothing would route.');

        return $workflow;
    }

    private function makeDraftRoute(): Workflow
    {
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Incident review',
                'description' => 'Every reported incident is reviewed by the HSE manager.',
                'entity_type' => 'hse_incident',
                'is_default' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Workflow::withoutGlobalScopes()->where('name', 'Incident review')->firstOrFail();
    }

    private function reportIncident(): Incident
    {
        $this->actingFresh($this->chidi)
            ->post(route('incidents.store'), [
                'title' => 'Scaffold plank slipped on the east platform',
                'description' => 'A board lifted as a fitter stepped onto it. Nobody was struck.',
                'project_id' => (string) $this->refinery->id,
                'org_unit_id' => (string) $this->site->id,
                'category' => 'near_miss',
                'severity' => 'minor',
                'potential_severity' => 'major',
                'location' => 'East platform, level 3',
                'occurred_at' => now()->subHours(2)->format('Y-m-d\TH:i'),
                'immediate_actions' => 'Platform barriered off and the gang stood down.',
                'confidentiality' => 'internal',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Incident::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** @param  array<string,mixed>  $overrides */
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

    private function instanceFor(Incident $incident): ?WorkflowInstance
    {
        return WorkflowInstance::withoutGlobalScopes()
            ->where('subject_type', 'hse_incident')
            ->where('subject_id', $incident->getKey())
            ->where('status', WorkflowInstance::STATUS_RUNNING)
            ->orderByDesc('id')
            ->first();
    }

    private function currentStage(WorkflowInstance $instance): WorkflowStageInstance
    {
        $stage = WorkflowStageInstance::withoutGlobalScopes()
            ->whereKey($instance->current_stage_instance_id)
            ->first();

        $this->assertNotNull($stage, 'The instance is running but sits on no stage.');

        return $stage;
    }
}
