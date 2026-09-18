<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * PHASE 6'S EXIT CRITERION, for the org chart: a promotion is agreed at approvals.decide, the same
 * endpoint a drawing and a leave request are decided at. There is no employee-movements.approve
 * route, and the first test says so out loud.
 *
 * The three other things a movements register has to get right:
 *
 *   1. APPROVING IS NOT APPLYING. A transfer approved in March to take effect in June must not move
 *      anybody in March. The route approves; the effective date applies; and the nightly sweep is
 *      what presses the button for the movements nobody remembers.
 *   2. APPLYING IS ITS OWN PERMISSION. This register is the only thing in the platform that writes
 *      somebody's position, department, site and reporting line, so a person who may raise the
 *      paperwork is not thereby a person who may change the org chart (§6).
 *   3. THE HISTORY DOES NOT MOVE. The "from" side is stamped off the personnel record, never posted,
 *      and an applied movement is editable by nobody at all (§84).
 */
final class EmployeeMovementTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $projects;

    private User $hr;           // Rita: raises movements, may not apply them

    private User $head;         // Tunde: Rita's line manager, approves what she raises

    private User $registrar;    // Femi: holds `apply` and nothing else that decides anything

    private User $employee;     // Kelechi: the person being promoted

    private Position $engineer;

    private Position $senior;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 09:00:00');

        $this->org = $this->makeOrganization('Meridian Movements');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, fn () => app(OrganizationProvisioner::class)->seedEmployeeMovementWorkflow($this->org));

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Department);
        $this->projects = $this->makeUnit($this->org, 'Projects', OrgUnitType::Department);

        // HR raises and manages movements across the company — and cannot apply one. That is the
        // separation the module exists to hold.
        $hrRole = $this->makeRole($this->org, 'HR Officer', [
            'employee.view' => 'organization', 'position.view' => 'organization',
            'org_unit.view' => 'organization', 'user.view' => 'organization',
            'employee_movement.view' => 'organization', 'employee_movement.create' => 'organization',
            'employee_movement.edit' => 'organization', 'employee_movement.delete' => 'organization',
            'employee_movement.manage' => 'organization', 'employee_movement.submit' => 'organization',
            // A movement is restricted: it names a person and says what is happening to them. HR
            // reads across departments and therefore holds the confidentiality grant; nobody reaches
            // another department's movements without it (§68).
            'employee_movement.view_confidential' => 'organization',
        ]);

        $headRole = $this->makeRole($this->org, 'Head of Corporate Services', [
            'employee.view' => 'organization',
            'employee_movement.view' => 'organization', 'employee_movement.view_confidential' => 'organization',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        $registrarRole = $this->makeRole($this->org, 'Personnel Registrar', [
            'employee.view' => 'organization',
            'employee_movement.view' => 'organization', 'employee_movement.view_confidential' => 'organization',
            'employee_movement.apply' => 'organization',
        ]);

        $staffRole = $this->makeRole($this->org, 'Engineer', [
            'employee.view' => 'own', 'employee_movement.view' => 'own',
        ]);

        $this->head = $this->makePerson($this->org, 'Tunde Bakare', null, [$headRole]);
        $this->hr = $this->makePerson($this->org, 'Rita Okonkwo', null, [$hrRole], $this->head->employee);
        $this->registrar = $this->makePerson($this->org, 'Femi Adeyemi', null, [$registrarRole]);
        $this->employee = $this->makePerson($this->org, 'Kelechi Nnaji', $this->engineering, [$staffRole]);

        $this->withinOrganization($this->org, function () {
            $this->engineer = Position::create([
                'organization_id' => $this->org->id, 'title' => 'Engineer', 'code' => 'ENG', 'grade' => 3, 'is_active' => true,
            ]);

            $this->senior = Position::create([
                'organization_id' => $this->org->id, 'title' => 'Senior Engineer', 'code' => 'SENG', 'grade' => 4, 'is_active' => true,
            ]);

            $this->employee->employee->forceFill(['position_id' => $this->engineer->id])->save();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the exit criterion

    #[Test]
    public function a_movement_is_decided_in_the_same_inbox_documents_use(): void
    {
        $this->assertFalse(Route::has('employee-movements.approve'), 'A movement must not have its own approve route.');

        $movement = $this->draftPromotion();

        $this->actingFresh($this->hr)
            ->post(route('employee-movements.submit', $movement))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('submitted', $this->reload($movement)->statusKey());

        // The route runs on the REQUESTER'S line — Rita raised it, so Tunde decides it.
        $stage = $this->pendingStage($movement);
        $this->assertTrue($stage->isAssignedTo($this->head));

        // Not the person being moved, and not the person who asked for it.
        $this->actingFresh($this->employee)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();
        $this->actingFresh($this->hr)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();

        $this->actingFresh($this->head)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $movement = $this->reload($movement);
        $this->assertSame('approved', $movement->statusKey());

        // APPROVED IS NOT APPLIED. It is March; the promotion is a June one, and Kelechi is still an
        // Engineer in Engineering this morning.
        $this->assertNull($movement->applied_at);
        $this->assertSame($this->engineer->id, $this->employeeRow()->position_id);
        $this->assertSame($this->engineering->id, $this->employeeRow()->org_unit_id);
    }

    // ================================================================== approving is not applying

    #[Test]
    public function an_approved_movement_waits_for_its_date_and_the_nightly_sweep_applies_it(): void
    {
        $movement = $this->approvedPromotion();

        // Pressing the button early is refused by the policy, so the route never reaches the service.
        $this->actingFresh($this->registrar)
            ->post(route('employee-movements.apply', $movement))
            ->assertForbidden();

        $this->assertNull($this->reload($movement)->applied_at);

        // June arrives and nobody presses anything.
        Carbon::setTestNow('2026-06-01 04:00:00');
        $this->artisan('movements:apply')->assertExitCode(0);

        $movement = $this->reload($movement);
        $this->assertSame('applied', $movement->statusKey());
        $this->assertNotNull($movement->applied_at);

        // Nobody is the actor: the platform did this because the date came, and putting the cron
        // account's name on the timeline would be a false audit entry (§59).
        $this->assertNull($movement->applied_by);

        $employee = $this->employeeRow();
        $this->assertSame($this->senior->id, $employee->position_id);
        $this->assertSame($this->projects->id, $employee->org_unit_id);

        // Safe to run twice: the second pass finds nothing and changes nothing.
        $this->artisan('movements:apply')->assertExitCode(0);
        $this->assertSame('applied', $this->reload($movement)->statusKey());
    }

    #[Test]
    public function applying_is_a_different_permission_from_raising_and_from_approving(): void
    {
        $movement = $this->approvedPromotion();
        Carbon::setTestNow('2026-06-01 09:00:00');

        // Rita raised it, manages the whole register, and may not write the org chart.
        $this->actingFresh($this->hr)->post(route('employee-movements.apply', $movement))->assertForbidden();

        // Tunde approved it. That was his part.
        $this->actingFresh($this->head)->post(route('employee-movements.apply', $movement))->assertForbidden();

        $this->actingFresh($this->registrar)
            ->post(route('employee-movements.apply', $movement))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $movement = $this->reload($movement);
        $this->assertSame('applied', $movement->statusKey());
        $this->assertSame($this->registrar->id, $movement->applied_by);
        $this->assertSame($this->senior->id, $this->employeeRow()->position_id);
    }

    #[Test]
    public function an_applied_movement_is_the_history_and_nobody_edits_it(): void
    {
        $movement = $this->approvedPromotion();
        Carbon::setTestNow('2026-06-01 09:00:00');
        $this->actingFresh($this->registrar)->post(route('employee-movements.apply', $movement))->assertRedirect();

        $movement = $this->reload($movement);

        $this->actingFresh($this->hr)->get(route('employee-movements.edit', $movement))->assertForbidden();
        $this->actingFresh($this->hr)->put(route('employee-movements.update', $movement), [
            'employee_id' => $this->employee->employee->id,
            'kind' => 'transfer',
            'effective_on' => '2026-06-01',
            'reason' => 'Rewriting what happened.',
        ])->assertForbidden();
        $this->actingFresh($this->hr)->delete(route('employee-movements.destroy', $movement))->assertForbidden();

        // And it cannot be applied a second time onto a person who has since moved again.
        $this->actingFresh($this->registrar)->post(route('employee-movements.apply', $movement))->assertForbidden();
    }

    // ================================================================== what the form cannot say

    #[Test]
    public function the_before_side_is_taken_from_the_record_and_never_from_the_form(): void
    {
        $this->actingFresh($this->hr)
            ->post(route('employee-movements.store'), [
                ...$this->promotionFields(),
                // Posted by somebody who read the schema. Both are ignored: `from_*` is a snapshot
                // and `applied_at` is the act of applying (§84).
                'from_position_id' => $this->senior->id,
                'applied_at' => '2026-03-10 09:00:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $movement = $this->latestMovement();

        $this->assertSame($this->engineer->id, $movement->from_position_id);
        $this->assertSame($this->engineering->id, $movement->from_org_unit_id);
        $this->assertNull($movement->applied_at);
        $this->assertSame($this->hr->id, $movement->requester_id);
    }

    #[Test]
    public function a_movement_that_changes_nothing_is_refused(): void
    {
        $this->actingFresh($this->hr)
            ->post(route('employee-movements.store'), [
                ...$this->promotionFields(),
                'to_position_id' => $this->engineer->id,   // the job he already has
                'to_org_unit_id' => $this->engineering->id, // the department he is already in
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => EmployeeMovement::query()->count()));
    }

    #[Test]
    public function two_movements_for_the_same_person_on_the_same_day_are_refused_at_submission(): void
    {
        $first = $this->draftPromotion();
        $this->actingFresh($this->hr)->post(route('employee-movements.submit', $first))->assertSessionHasNoErrors();

        $this->actingFresh($this->hr)->post(route('employee-movements.store'), [
            ...$this->promotionFields(),
            'kind' => 'transfer',
            'to_org_unit_id' => $this->projects->id,
            'to_position_id' => null,
        ])->assertSessionHasNoErrors();

        $second = $this->latestMovement();

        $this->actingFresh($this->hr)
            ->post(route('employee-movements.submit', $second))
            ->assertSessionHasErrors('movement');

        $this->assertSame('draft', $this->reload($second)->statusKey());
    }

    // ================================================================== the boundaries

    #[Test]
    public function a_movement_from_another_company_is_not_found(): void
    {
        $movement = $this->approvedPromotion();

        $other = $this->makeOrganization('Other Engineering');
        $this->provisionConfiguration($other);
        $stranger = $this->makePerson($other, 'Sani Musa', null, [
            $this->makeRole($other, 'HR', [
                'employee.view' => 'organization',
                'employee_movement.view' => 'organization', 'employee_movement.manage' => 'organization',
                'employee_movement.apply' => 'organization', 'employee_movement.submit' => 'organization',
                'employee_movement.view_confidential' => 'organization',
            ]),
        ]);

        $this->actingFresh($stranger)->get(route('employee-movements.show', $movement))->assertNotFound();
        $this->actingFresh($stranger)->post(route('employee-movements.apply', $movement))->assertNotFound();
        $this->actingFresh($stranger)->post(route('employee-movements.cancel', $movement), ['reason' => 'Mine now.'])->assertNotFound();
    }

    #[Test]
    public function a_movement_cannot_be_raised_for_somebody_outside_the_raisers_reach(): void
    {
        // A departmental HR person: real permissions, a real department, and Engineering is not it.
        $local = $this->makePerson($this->org, 'Bisi Lawal', $this->projects, [
            $this->makeRole($this->org, 'Projects Administrator', [
                'employee.view' => 'department', 'position.view' => 'organization', 'org_unit.view' => 'organization',
                'employee_movement.view' => 'department', 'employee_movement.create' => 'department',
                'employee_movement.submit' => 'department',
            ]),
        ]);

        // Kelechi's id is right there in the form. The store re-authorizes the person (§94).
        $this->actingFresh($local)
            ->post(route('employee-movements.store'), $this->promotionFields())
            ->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => EmployeeMovement::query()->count()));
    }

    #[Test]
    public function cancelling_stops_a_movement_that_has_been_approved_but_not_applied(): void
    {
        $movement = $this->approvedPromotion();

        $this->actingFresh($this->hr)
            ->post(route('employee-movements.cancel', $movement), ['reason' => 'The role was withdrawn by the client.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $movement = $this->reload($movement);
        $this->assertSame('cancelled', $movement->statusKey());

        // And the sweep leaves a cancelled movement alone when its date comes round.
        Carbon::setTestNow('2026-06-01 04:00:00');
        $this->artisan('movements:apply')->assertExitCode(0);

        $this->assertSame($this->engineer->id, $this->employeeRow()->position_id);
    }

    // ================================================================== the screens

    #[Test]
    public function every_movement_screen_renders(): void
    {
        $movement = $this->approvedPromotion();

        $this->actingFresh($this->hr)->get(route('employee-movements.index'))->assertOk()->assertSee($movement->number);
        $this->actingFresh($this->hr)->get(route('employee-movements.index', ['view' => 'all', 'kind' => 'promotion', 'q' => 'Kelechi']))->assertOk();
        $this->actingFresh($this->hr)->get(route('employee-movements.show', $movement))->assertOk()->assertSee('Senior Engineer');
        $this->actingFresh($this->hr)->get(route('employee-movements.create'))->assertOk();
        $this->actingFresh($this->hr)->get(route('employee-movements.create', ['employee' => $this->employee->employee->id]))->assertOk();

        $draft = $this->draftTransfer();
        $this->actingFresh($this->hr)->get(route('employee-movements.edit', $draft))->assertOk();

    }

    #[Test]
    public function the_person_being_moved_reads_the_paperwork_once_it_has_actually_happened(): void
    {
        $movement = $this->approvedPromotion();

        // Approved in March, effective in June. HR is still arranging it and Kelechi has not been
        // moved — "we are thinking of putting him in Projects" is not his to read yet (§68).
        $this->actingFresh($this->employee)->get(route('employee-movements.show', $movement))->assertForbidden();

        Carbon::setTestNow('2026-06-01 04:00:00');
        $this->artisan('movements:apply')->assertExitCode(0);

        // Now it is on his personnel record, and he may read the document that put it there.
        $this->actingFresh($this->employee)
            ->get(route('employee-movements.show', $movement))
            ->assertOk()
            ->assertSee('Senior Engineer');

        // Reading it is not managing it.
        $this->actingFresh($this->employee)
            ->post(route('employee-movements.cancel', $movement), ['reason' => 'I changed my mind.'])
            ->assertForbidden();
    }

    // ================================================================== fixture helpers

    /** @return array<string,mixed> */
    private function promotionFields(): array
    {
        return [
            'employee_id' => $this->employee->employee->id,
            'kind' => 'promotion',
            'to_position_id' => $this->senior->id,
            'to_org_unit_id' => $this->projects->id,
            'effective_on' => '2026-06-01',
            'reason' => 'Took the Bonny tie-in package through to handover.',
        ];
    }

    private function draftPromotion(): EmployeeMovement
    {
        $this->actingFresh($this->hr)
            ->post(route('employee-movements.store'), $this->promotionFields())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->latestMovement();
    }

    /** A second draft on a different date, for the screens that need one. */
    private function draftTransfer(): EmployeeMovement
    {
        $this->actingFresh($this->hr)
            ->post(route('employee-movements.store'), [
                ...$this->promotionFields(),
                'kind' => 'transfer',
                'to_position_id' => null,
                'effective_on' => '2026-09-01',
                'reason' => 'Moving to the Projects office.',
            ])
            ->assertSessionHasNoErrors();

        return $this->latestMovement();
    }

    private function approvedPromotion(): EmployeeMovement
    {
        $movement = $this->draftPromotion();
        $this->actingFresh($this->hr)->post(route('employee-movements.submit', $movement))->assertSessionHasNoErrors();

        $stage = $this->pendingStage($movement);
        $this->actingFresh($this->head)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        return $this->reload($movement);
    }

    private function latestMovement(): EmployeeMovement
    {
        return $this->withinOrganization($this->org, fn () => EmployeeMovement::query()->latest('id')->firstOrFail()->load('status'));
    }

    private function reload(EmployeeMovement $movement): EmployeeMovement
    {
        return $this->withinOrganization($this->org, fn () => $movement->fresh(['status', 'employee']));
    }

    private function employeeRow(): Employee
    {
        return $this->withinOrganization($this->org, fn () => Employee::query()->findOrFail($this->employee->employee->id));
    }

    private function pendingStage(Model $subject): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', 'employee_movement')->where('subject_id', $subject->getKey()))
            ->firstOrFail());
    }
}
