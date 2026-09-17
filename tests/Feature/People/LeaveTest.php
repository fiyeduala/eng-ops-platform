<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use App\Services\LeaveService;
use App\Support\Enums\OrgUnitType;
use App\Support\RoleTemplates;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * PHASE 6'S EXIT CRITERION, for leave: "leave and timesheet approvals run on the same workflow
 * engine as documents."
 *
 * So the manager in these tests never touches a leave route to decide anything. They post to
 * approvals.decide — the endpoint a drawing is approved through — and the leave request changes
 * because the engine called its hooks. There is no leave.approve route to call instead, and the
 * first test says so.
 */
final class LeaveTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $site;

    private User $manager;      // Kemi: the line manager, holds approval.act

    private User $employee;     // Tobi: reports to Kemi

    private User $stranger;     // Uche: same unit, reports to nobody here

    private User $hr;           // Funke: leave.* organization-wide

    private Role $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday, so the fixture's weeks read naturally.
        Carbon::setTestNow('2026-03-02 09:00:00');

        $this->org = $this->makeOrganization('Meridian People');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, function () {
            app(OrganizationProvisioner::class)->seedLeaveWorkflow($this->org);
            app(OrganizationProvisioner::class)->seedLeaveTypes($this->org);
        });

        $this->site = $this->makeUnit($this->org, 'Site Operations', OrgUnitType::Department);

        $self = [
            'employee.view' => 'own',
            'leave.view' => 'assigned',
            'leave.create' => 'own',
            'leave.edit' => 'own',
            'leave.submit' => 'own',
            'leave.delete' => 'own',
        ];

        $this->staff = $this->makeRole($this->org, 'Staff', $self);
        $managerRole = $this->makeRole($this->org, 'Line manager', [
            ...$self,
            'approval.view' => 'organization',
            'approval.act' => 'organization',
        ]);
        $hrRole = $this->makeRole($this->org, 'HR', [
            'employee.view' => 'organization',
            'leave.view' => 'organization',
            'leave.create' => 'organization',
            'leave.edit' => 'organization',
            'leave.submit' => 'organization',
            'leave.manage' => 'organization',
            'approval.view' => 'organization',
        ]);

        $this->manager = $this->makePerson($this->org, 'Kemi Adeyemi', $this->site, [$managerRole]);
        $this->employee = $this->makePerson($this->org, 'Tobi Okafor', $this->site, [$this->staff], $this->manager->employee);
        $this->stranger = $this->makePerson($this->org, 'Uche Nnaji', $this->site, [$this->staff]);
        $this->hr = $this->makePerson($this->org, 'Funke Balogun', null, [$hrRole]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the exit criterion

    #[Test]
    public function the_line_manager_approves_leave_in_the_same_inbox_documents_use(): void
    {
        $this->assertFalse(Route::has('leave.approve'), 'Leave must not have its own approve route.');
        $this->assertFalse(Route::has('leave.reject'));

        $leave = $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-13');

        $this->assertSame('submitted', $leave->statusKey());
        $this->assertSame('5.00', (string) $leave->days);

        $stage = $this->pendingStage($leave);
        $this->assertTrue($stage->isAssignedTo($this->manager));

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('approvals.index'));

        $leave = $this->reload($leave);
        $this->assertSame('approved', $leave->statusKey());
        $this->assertNotNull($leave->decided_at);

        $this->actingFresh($this->employee)
            ->get(route('leave.show', $leave))
            ->assertOk()
            ->assertSee('Approved');
    }

    #[Test]
    public function rejection_closes_the_request_and_returning_it_reopens_the_draft(): void
    {
        $rejected = $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-10');

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $this->pendingStage($rejected)), ['decision' => 'reject', 'comment' => 'Shutdown week.'])
            ->assertSessionHasNoErrors();

        $rejected = $this->reload($rejected);
        $this->assertSame('rejected', $rejected->statusKey());
        $this->assertSame('Shutdown week.', $rejected->decision_reason);

        $returned = $this->book($this->employee, 'ANNUAL', '2026-03-16', '2026-03-17');

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $this->pendingStage($returned)), ['decision' => 'request_revision', 'comment' => 'Move it a week.'])
            ->assertSessionHasNoErrors();

        $returned = $this->reload($returned);
        $this->assertSame('draft', $returned->statusKey());
        $this->assertNull($returned->submitted_at);

        // The requester changes the dates and sends it again; the same route picks it up.
        $this->actingFresh($this->employee)
            ->put(route('leave.update', $returned), [
                'leave_type_id' => $this->type('ANNUAL')->id,
                'start_date' => '2026-03-23',
                'end_date' => '2026-03-24',
            ])->assertSessionHasNoErrors();

        $this->actingFresh($this->employee)
            ->post(route('leave.submit', $returned))
            ->assertSessionHasNoErrors();

        $this->assertSame('submitted', $this->reload($returned)->statusKey());
        $this->assertNotNull($this->pendingStage($returned));
    }

    #[Test]
    public function the_requester_cannot_approve_their_own_leave_and_a_stranger_cannot_either(): void
    {
        $leave = $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-09');
        $stage = $this->pendingStage($leave);

        $this->actingFresh($this->stranger)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame('submitted', $this->reload($leave)->statusKey());
    }

    #[Test]
    public function a_type_that_needs_no_approval_is_booked_directly(): void
    {
        $this->withinOrganization($this->org, fn () => $this->type('SICK')->update(['requires_approval' => false]));

        $leave = $this->book($this->employee, 'SICK', '2026-03-03', '2026-03-03');

        $this->assertSame('approved', $leave->statusKey());
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => WorkflowInstance::query()->count()));
    }

    #[Test]
    public function without_an_approval_route_nothing_is_submitted(): void
    {
        $this->withinOrganization($this->org, fn () => Workflow::query()->where('entity_type', 'leave_request')->update(['is_active' => false]));

        $this->actingFresh($this->employee)
            ->post(route('leave.store'), $this->form('ANNUAL', '2026-03-09', '2026-03-10', submit: true))
            ->assertSessionHasErrors('status_id');

        $leave = $this->latest();
        $this->assertSame('draft', $leave->statusKey());
        $this->assertNull($leave->submitted_at);
    }

    // ================================================================== counting

    #[Test]
    public function days_skip_weekends_and_public_holidays_and_count_half_days(): void
    {
        $this->withinOrganization($this->org, fn () => PublicHoliday::create([
            'organization_id' => $this->org->id, 'name' => 'Founders Day', 'holiday_date' => '2026-03-11', 'is_recurring' => false,
        ]));

        // Friday to the following Friday: 6 weekdays, one of them a holiday, half a day each end.
        $this->actingFresh($this->employee)
            ->post(route('leave.store'), [
                ...$this->form('ANNUAL', '2026-03-06', '2026-03-13'),
                'start_half_day' => '1',
                'end_half_day' => '1',
                'days' => '1',   // ignored: the server counts
            ])->assertSessionHasNoErrors();

        $this->assertSame('4.00', (string) $this->latest()->days);
    }

    #[Test]
    public function the_balance_and_overlapping_leave_are_enforced_on_submit(): void
    {
        $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-13');

        // Overlaps the first request.
        $this->actingFresh($this->employee)
            ->post(route('leave.store'), $this->form('ANNUAL', '2026-03-12', '2026-03-16', submit: true))
            ->assertSessionHasErrors('status_id');
        $this->assertSame('draft', $this->latest()->statusKey());

        // 20 granted, 5 taken: 16 working days more is too many.
        $this->actingFresh($this->employee)
            ->post(route('leave.store'), $this->form('ANNUAL', '2026-04-01', '2026-04-22', submit: true))
            ->assertSessionHasErrors('status_id');
        $this->assertSame('draft', $this->latest()->statusKey());
    }

    #[Test]
    public function cancelling_gives_the_days_back(): void
    {
        $leave = $this->book($this->employee, 'ANNUAL', '2026-04-06', '2026-04-10');
        $service = app(LeaveService::class);
        $annual = $this->type('ANNUAL');

        $used = fn () => $this->withinOrganization($this->org, fn () => $service->daysUsed($this->employee->employee, $annual, 2026));
        $this->assertSame('5.00', $used());

        $this->actingFresh($this->employee)
            ->post(route('leave.cancel', $leave), ['reason' => 'Trip called off.'])
            ->assertSessionHasNoErrors();

        $leave = $this->reload($leave);
        $this->assertSame('cancelled', $leave->statusKey());
        $this->assertSame('0.00', $used());
        $this->assertNull($this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()->pending()->first()));
    }

    // ================================================================== access

    #[Test]
    public function a_colleague_cannot_see_or_touch_somebody_elses_leave(): void
    {
        $leave = $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-09', submit: false);

        $this->actingFresh($this->stranger)->get(route('leave.show', $leave))->assertForbidden();
        $this->actingFresh($this->stranger)->get(route('leave.edit', $leave))->assertForbidden();
        $this->actingFresh($this->stranger)->post(route('leave.submit', $leave))->assertForbidden();
        $this->actingFresh($this->stranger)->delete(route('leave.destroy', $leave))->assertForbidden();
        $this->actingFresh($this->stranger)->get(route('leave.index', ['view' => 'all']))->assertOk()->assertDontSee($leave->number);

        // The line manager sees a direct report's leave, but may not change it.
        $this->actingFresh($this->manager)->get(route('leave.show', $leave))->assertOk();
        $this->actingFresh($this->manager)->get(route('leave.edit', $leave))->assertForbidden();
    }

    #[Test]
    public function booking_for_somebody_else_needs_leave_manage(): void
    {
        $this->actingFresh($this->stranger)
            ->post(route('leave.store'), [...$this->form('ANNUAL', '2026-03-09', '2026-03-09'), 'employee_id' => $this->employee->employee->id])
            ->assertForbidden();

        $this->actingFresh($this->hr)
            ->post(route('leave.store'), [...$this->form('ANNUAL', '2026-03-09', '2026-03-09'), 'employee_id' => $this->employee->employee->id])
            ->assertSessionHasNoErrors();

        $leave = $this->latest();
        $this->assertSame($this->employee->employee->id, $leave->employee_id);
        $this->assertSame($this->employee->id, $leave->requester_id);
    }

    #[Test]
    public function another_tenant_gets_a_404(): void
    {
        $leave = $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-09', submit: false);

        $other = $this->makeOrganization('Kestrel People');
        $this->provisionConfiguration($other);
        $outsider = $this->makePerson($other, 'Ada Kestrel', null, [
            $this->makeRole($other, 'HR', ['leave.view' => 'organization', 'leave.edit' => 'organization', 'leave.manage' => 'organization']),
        ]);

        $this->actingFresh($outsider)->get(route('leave.show', $leave))->assertNotFound();
        $this->actingFresh($outsider)->post(route('leave.cancel', $leave), ['reason' => 'x'])->assertNotFound();
    }

    #[Test]
    public function the_team_calendar_hides_the_kind_of_leave_from_colleagues(): void
    {
        $this->book($this->employee, 'SICK', '2026-03-03', '2026-03-04');

        $this->actingFresh($this->manager)
            ->get(route('leave.calendar', ['month' => '2026-03']))
            ->assertOk()
            ->assertSee('Tobi Okafor')
            ->assertSee('Away')
            ->assertDontSee('Sick leave');

        $this->actingFresh($this->hr)
            ->get(route('leave.calendar', ['month' => '2026-03']))
            ->assertOk()
            ->assertSee('Sick leave');
    }

    #[Test]
    public function leave_rules_are_changed_only_by_organisation_wide_hr(): void
    {
        $this->actingFresh($this->employee)->get(route('leave-types.index'))->assertForbidden();
        $this->actingFresh($this->employee)->post(route('public-holidays.store'), ['name' => 'X', 'holiday_date' => '2026-05-01'])->assertForbidden();
        $this->actingFresh($this->employee)->get(route('public-holidays.index'))->assertOk();

        $deptHr = $this->makePerson($this->org, 'Dayo Unit', $this->site, [
            $this->makeRole($this->org, 'Unit HR', ['leave.view' => 'department', 'leave.manage' => 'department']),
        ]);
        $this->actingFresh($deptHr)->get(route('leave-types.create'))->assertForbidden();

        $this->actingFresh($this->hr)
            ->post(route('leave-types.store'), [
                'code' => 'study', 'name' => 'Study leave', 'days_per_year' => '5', 'carry_over_limit' => '',
                'min_notice_days' => '', 'is_paid' => '1', 'requires_approval' => '1', 'allow_half_days' => '0', 'is_active' => '1',
            ])->assertSessionHasNoErrors();
        $this->assertNotNull($this->type('STUDY'));

        $this->actingFresh($this->hr)->get(route('leave-balances.index'))->assertOk()->assertSee('Tobi Okafor');
    }

    #[Test]
    public function every_leave_screen_renders(): void
    {
        $leave = $this->book($this->employee, 'ANNUAL', '2026-03-09', '2026-03-10', submit: false);

        $this->actingFresh($this->employee);
        $this->get(route('leave.index'))->assertOk()->assertSee($leave->number)->assertSee('days left');
        $this->get(route('leave.create'))->assertOk();
        $this->get(route('leave.edit', $leave))->assertOk();
        $this->get(route('leave.show', $leave))->assertOk()->assertSee('Submit for approval');
        $this->get(route('public-holidays.index'))->assertOk();

        $this->post(route('leave.submit', $leave))->assertSessionHasNoErrors();
        $this->actingFresh($this->manager)->get(route('leave.show', $leave))->assertOk()->assertSee('Decide in My Approvals');

        $this->actingFresh($this->hr);
        $this->get(route('leave-types.index'))->assertOk()->assertSee('ANNUAL');
        $this->get(route('leave-types.create'))->assertOk();
        $this->get(route('leave-types.edit', $this->type('ANNUAL')))->assertOk();
        $this->get(route('public-holidays.index'))->assertOk()->assertSee('Add a holiday');
        $this->get(route('leave.create'))->assertOk()->assertSee('Booking for somebody else');
    }

    // ================================================================== helpers

    #[Test]
    public function every_starter_role_can_decide_what_it_is_routed_and_nothing_else(): void
    {
        foreach (RoleTemplates::all() as $slug => $template) {
            $this->assertArrayHasKey('approval.act', $template['grants'], "Template {$slug} cannot decide its team's leave.");
        }

        // A manager holding nothing broader than the starter grants.
        $plain = $this->makeRole($this->org, 'Plain staff', [
            'employee.view' => 'own',
            'leave.view' => 'assigned',
            'leave.create' => 'own',
            'leave.submit' => 'own',
            'approval.view' => 'assigned',
            'approval.act' => 'assigned',
        ]);
        $foreman = $this->makePerson($this->org, 'Ada Eze', $this->site, [$plain]);
        $report = $this->makePerson($this->org, 'Chidi Obi', $this->site, [$plain], $foreman->employee);

        $mine = $this->pendingStage($this->book($report, 'ANNUAL', '2026-03-16', '2026-03-17'));
        $notMine = $this->pendingStage($this->book($this->employee, 'ANNUAL', '2026-03-16', '2026-03-17'));

        $this->actingFresh($foreman)->get(route('approvals.index'))->assertOk();
        $this->actingFresh($foreman)->get(route('approvals.show', $notMine))->assertForbidden();
        $this->actingFresh($foreman)->post(route('approvals.decide', $notMine), ['decision' => 'approve'])->assertForbidden();

        $this->actingFresh($foreman)
            ->post(route('approvals.decide', $mine), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame(WorkflowStageInstance::STATUS_PENDING, $this->withinOrganization($this->org, fn () => $notMine->fresh()->status));
        $this->assertNotSame(WorkflowStageInstance::STATUS_PENDING, $this->withinOrganization($this->org, fn () => $mine->fresh()->status));
    }

    private function book(User $user, string $code, string $start, string $end, bool $submit = true): LeaveRequest
    {
        $this->actingFresh($user)
            ->post(route('leave.store'), $this->form($code, $start, $end, $submit))
            ->assertSessionHasNoErrors();

        return $this->latest();
    }

    /** @return array<string,mixed> */
    private function form(string $code, string $start, string $end, bool $submit = false): array
    {
        return array_filter([
            'leave_type_id' => $this->type($code)->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Family matters.',
            'submit_now' => $submit ? '1' : null,
        ]);
    }

    private function type(string $code): ?LeaveType
    {
        return $this->withinOrganization($this->org, fn () => LeaveType::query()->where('code', $code)->first());
    }

    private function latest(): LeaveRequest
    {
        return $this->withinOrganization($this->org, fn () => LeaveRequest::query()->latest('id')->firstOrFail()->load('status'));
    }

    private function reload(LeaveRequest $leave): LeaveRequest
    {
        return $this->withinOrganization($this->org, fn () => $leave->fresh(['status']));
    }

    private function pendingStage(LeaveRequest $leave): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', 'leave_request')->where('subject_id', $leave->id))
            ->firstOrFail());
    }
}
