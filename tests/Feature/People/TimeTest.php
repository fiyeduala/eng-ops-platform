<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\AttendanceRecord;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use App\Services\ProjectTeamService;
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
 * PHASE 6'S EXIT CRITERION, for timesheets (and overtime, which follows the same road): the manager
 * decides at approvals.decide, the endpoint a drawing is approved through. Nothing here posts to a
 * timesheet approve route, because there is none.
 *
 * Plus the attendance rules that make hours trustworthy: one open shift, server time, and nobody
 * rewrites their own.
 */
final class TimeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $site;

    private User $manager;      // Kemi: line manager, approval.act

    private User $employee;     // Tobi: reports to Kemi, on the Lekki project

    private User $stranger;     // Uche: same unit, reports to nobody here

    private User $hr;           // Funke: attendance/timesheet/overtime at organization

    private Project $lekki;

    private Project $abuja;     // nobody in the fixture but HR works on this

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-02 09:00:00');   // a Monday

        $this->org = $this->makeOrganization('Meridian Time');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, function () {
            app(OrganizationProvisioner::class)->seedTimesheetWorkflow($this->org);
            app(OrganizationProvisioner::class)->seedOvertimeWorkflow($this->org);
        });

        $this->site = $this->makeUnit($this->org, 'Site Operations', OrgUnitType::Department);

        $self = [
            'employee.view' => 'own',
            'timesheet.view' => 'assigned', 'timesheet.create' => 'own', 'timesheet.edit' => 'own',
            'timesheet.submit' => 'own', 'timesheet.delete' => 'own',
            'attendance.view' => 'assigned', 'attendance.create' => 'own',
            'overtime.view' => 'assigned', 'overtime.create' => 'own', 'overtime.edit' => 'own',
            'overtime.submit' => 'own', 'overtime.delete' => 'own',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ];

        $staff = $this->makeRole($this->org, 'Staff', $self);
        $hrRole = $this->makeRole($this->org, 'HR', [
            ...$self,
            'employee.view' => 'organization',
            'project.view' => 'organization',
            'attendance.view' => 'organization', 'attendance.edit' => 'organization',
            'timesheet.view' => 'organization', 'timesheet.create' => 'organization', 'timesheet.edit' => 'organization',
            'timesheet.manage' => 'organization',
            'overtime.view' => 'organization', 'overtime.create' => 'organization', 'overtime.manage' => 'organization',
        ]);

        $this->manager = $this->makePerson($this->org, 'Kemi Adeyemi', $this->site, [$staff]);
        $this->employee = $this->makePerson($this->org, 'Tobi Okafor', $this->site, [$staff], $this->manager->employee);
        $this->stranger = $this->makePerson($this->org, 'Uche Nnaji', $this->site, [$staff]);
        $this->hr = $this->makePerson($this->org, 'Funke Balogun', null, [$hrRole]);

        $this->lekki = $this->makeProject($this->org, $this->hr, 'Lekki Pumping Station');
        $this->abuja = $this->makeProject($this->org, $this->hr, 'Abuja Ring Road');
        $this->withinOrganization($this->org, fn () => app(ProjectTeamService::class)
            ->addMember($this->lekki, ['user_id' => $this->employee->id], $this->hr));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the exit criterion

    #[Test]
    public function the_line_manager_approves_a_timesheet_in_the_same_inbox_documents_use(): void
    {
        $this->assertFalse(Route::has('timesheets.approve'), 'Timesheets must not have their own approve route.');
        $this->assertFalse(Route::has('overtime.approve'));

        $sheet = $this->openWeek($this->employee);
        $this->assertSame('draft', $sheet->statusKey());
        $this->assertSame('2026-03-02', $sheet->period_start->toDateString());
        $this->assertSame('2026-03-08', $sheet->period_end->toDateString());

        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), [
                'lines' => [$this->line($this->lekki, ['2026-03-02' => '8', '2026-03-03' => '7.5'])],
                'submit_now' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('timesheets.show', $sheet));

        $sheet = $this->reload($sheet);
        $this->assertSame('submitted', $sheet->statusKey());
        $this->assertSame('15.50', (string) $sheet->total_hours);

        $stage = $this->pendingStage('timesheet', $sheet);
        $this->assertTrue($stage->isAssignedTo($this->manager));

        // The person whose hours they are cannot decide them, nor can a colleague.
        $this->actingFresh($this->employee)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();
        $this->actingFresh($this->stranger)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $this->reload($sheet)->statusKey());
        $this->actingFresh($this->manager)->get(route('timesheets.show', $sheet))->assertOk()->assertSee('15.5');
    }

    #[Test]
    public function a_returned_sheet_is_a_draft_again_and_goes_back_down_the_same_route(): void
    {
        $sheet = $this->submittedSheet();

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $this->pendingStage('timesheet', $sheet)), ['decision' => 'request_revision', 'comment' => 'Tuesday was a site holiday.'])
            ->assertSessionHasNoErrors();

        $sheet = $this->reload($sheet);
        $this->assertSame('draft', $sheet->statusKey());
        $this->assertSame('Tuesday was a site holiday.', $sheet->decision_reason);

        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-02' => '8'])], 'submit_now' => '1'])
            ->assertSessionHasNoErrors();

        $sheet = $this->reload($sheet);
        $this->assertSame('submitted', $sheet->statusKey());
        $this->assertSame('8.00', (string) $sheet->total_hours);
        $this->assertNotNull($this->pendingStage('timesheet', $sheet));
    }

    #[Test]
    public function without_a_route_a_sheet_is_not_submitted(): void
    {
        $this->withinOrganization($this->org, fn () => Workflow::query()->where('entity_type', 'timesheet')->update(['is_active' => false]));

        $sheet = $this->openWeek($this->employee);
        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-02' => '8'])], 'submit_now' => '1'])
            ->assertSessionHasErrors('lines');

        $this->assertSame('draft', $this->reload($sheet)->statusKey());
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => WorkflowInstance::query()->count()));
    }

    // ================================================================== timesheet rules

    #[Test]
    public function hours_go_only_on_projects_the_person_works_on_and_within_the_week(): void
    {
        $sheet = $this->openWeek($this->employee);

        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->abuja, ['2026-03-02' => '8'])]])
            ->assertSessionHasErrors('lines');

        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-09' => '8'])]])
            ->assertSessionHasErrors('lines');

        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [
                $this->line($this->lekki, ['2026-03-02' => '20']),
                $this->line($this->lekki, ['2026-03-02' => '6']),
            ]])
            ->assertSessionHasErrors('lines');

        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-02' => '-1'])]])
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => TimesheetEntry::query()->count()));

        // The Abuja project is not even offered on the grid.
        $this->actingFresh($this->employee)->get(route('timesheets.edit', $sheet))
            ->assertOk()->assertSee('Lekki Pumping Station')->assertDontSee('Abuja Ring Road');

        // An empty sheet cannot be sent.
        $this->actingFresh($this->employee)->post(route('timesheets.submit', $sheet))->assertSessionHasErrors('status_id');
    }

    #[Test]
    public function one_sheet_a_week_and_opening_it_again_returns_the_same_sheet(): void
    {
        $sheet = $this->openWeek($this->employee);

        $this->actingFresh($this->employee)
            ->post(route('timesheets.store'), ['week' => '2026-03-05'])
            ->assertRedirect(route('timesheets.edit', $sheet));

        $this->assertSame(1, $this->withinOrganization($this->org, fn () => Timesheet::query()->count()));
    }

    #[Test]
    public function a_colleague_cannot_open_fill_in_or_send_somebody_elses_sheet(): void
    {
        $sheet = $this->openWeek($this->employee);

        $this->actingFresh($this->stranger)->get(route('timesheets.show', $sheet))->assertForbidden();
        $this->actingFresh($this->stranger)->get(route('timesheets.edit', $sheet))->assertForbidden();
        $this->actingFresh($this->stranger)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-02' => '8'])]])
            ->assertForbidden();
        $this->actingFresh($this->stranger)->post(route('timesheets.submit', $sheet))->assertForbidden();
        $this->actingFresh($this->stranger)->delete(route('timesheets.destroy', $sheet))->assertForbidden();
        $this->actingFresh($this->stranger)->get(route('timesheets.index', ['view' => 'all']))->assertOk()->assertDontSee($sheet->number);

        // Opening a week for somebody else needs timesheet.manage.
        $this->actingFresh($this->stranger)
            ->post(route('timesheets.store'), ['week' => '2026-03-02', 'employee_id' => $this->employee->employee->id])
            ->assertForbidden();

        // The line manager can read the report's sheet but not fill it in.
        $this->actingFresh($this->manager)->get(route('timesheets.show', $sheet))->assertOk();
        $this->actingFresh($this->manager)->get(route('timesheets.edit', $sheet))->assertForbidden();

        // A submitted sheet is frozen even for its owner.
        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-02' => '8'])], 'submit_now' => '1'])
            ->assertSessionHasNoErrors();
        $this->actingFresh($this->employee)->get(route('timesheets.edit', $sheet))->assertForbidden();
    }

    // ================================================================== attendance

    #[Test]
    public function nobody_is_clocked_in_twice_and_the_times_are_the_servers(): void
    {
        $this->actingFresh($this->employee)
            ->post(route('attendance.clock-in'), ['project_id' => $this->lekki->id, 'clock_in_at' => '2026-03-01 06:00:00', 'user_id' => $this->stranger->id])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->employee)->post(route('attendance.clock-in'))->assertSessionHasErrors('clock');

        $records = $this->withinOrganization($this->org, fn () => AttendanceRecord::query()->get());
        $this->assertCount(1, $records);
        $this->assertSame($this->employee->id, (int) $records[0]->user_id);
        $this->assertSame('2026-03-02 09:00:00', $records[0]->clock_in_at->format('Y-m-d H:i:s'));
        $this->assertNull($records[0]->clock_in_latitude, 'Location is off by default.');

        Carbon::setTestNow('2026-03-02 17:30:00');
        $this->actingFresh($this->employee)->post(route('attendance.clock-out'))->assertSessionHasNoErrors();
        $this->actingFresh($this->employee)->post(route('attendance.clock-out'))->assertSessionHasErrors('clock');

        $record = $this->withinOrganization($this->org, fn () => $records[0]->fresh());
        $this->assertSame(510, (int) $record->minutes);

        // Clocking in against a project the person is not on is refused outright.
        $this->actingFresh($this->employee)->post(route('attendance.clock-in'), ['project_id' => $this->abuja->id])->assertForbidden();
    }

    #[Test]
    public function nobody_corrects_their_own_shift_and_a_correction_needs_a_reason(): void
    {
        $this->actingFresh($this->employee)->post(route('attendance.clock-in'))->assertSessionHasNoErrors();
        $this->actingFresh($this->hr)->post(route('attendance.clock-in'))->assertSessionHasNoErrors();

        [$tobis, $funkes] = $this->withinOrganization($this->org, fn () => [
            AttendanceRecord::query()->where('user_id', $this->employee->id)->firstOrFail(),
            AttendanceRecord::query()->where('user_id', $this->hr->id)->firstOrFail(),
        ]);

        // The person themselves — and their line manager, who holds no attendance.edit — cannot.
        $this->actingFresh($this->employee)->get(route('attendance.edit', $tobis))->assertForbidden();
        $this->actingFresh($this->manager)->get(route('attendance.edit', $tobis))->assertForbidden();

        // HR cannot rewrite HR's own shift, nor enter one for themselves.
        $this->actingFresh($this->hr)
            ->put(route('attendance.update', $funkes), ['clock_in_at' => '2026-03-02 07:00', 'reason' => 'Forgot.'])
            ->assertForbidden();
        $this->actingFresh($this->hr)
            ->post(route('attendance.store'), ['employee_id' => $this->hr->employee->id, 'clock_in_at' => '2026-02-27 08:00', 'clock_out_at' => '2026-02-27 16:00', 'reason' => 'x'])
            ->assertForbidden();

        $this->actingFresh($this->hr)
            ->put(route('attendance.update', $tobis), ['clock_in_at' => '2026-03-02 07:00'])
            ->assertSessionHasErrors('reason');

        $this->actingFresh($this->hr)
            ->put(route('attendance.update', $tobis), ['clock_in_at' => '2026-03-02 07:00', 'reason' => 'Gate log shows 07:00.'])
            ->assertSessionHasNoErrors();

        $tobis = $this->withinOrganization($this->org, fn () => $tobis->fresh());
        $this->assertSame('07:00', $tobis->clock_in_at->format('H:i'));
        $this->assertSame($this->hr->id, (int) $tobis->adjusted_by);

        // A missed Friday, entered for Tobi.
        $this->actingFresh($this->hr)
            ->post(route('attendance.store'), ['employee_id' => $this->employee->employee->id, 'clock_in_at' => '2026-02-27 08:00', 'clock_out_at' => '2026-02-27 16:00', 'reason' => 'Phone was dead.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->withinOrganization($this->org, fn () => AttendanceRecord::query()->where('source', AttendanceRecord::SOURCE_MANUAL)->count()));

        // Overlapping an existing shift is refused.
        $this->actingFresh($this->hr)
            ->post(route('attendance.store'), ['employee_id' => $this->employee->employee->id, 'clock_in_at' => '2026-02-27 12:00', 'clock_out_at' => '2026-02-27 18:00', 'reason' => 'Again.'])
            ->assertSessionHasErrors('clock_in_at');
    }

    // ================================================================== overtime

    #[Test]
    public function overtime_is_approved_in_the_inbox_and_worked_hours_cannot_exceed_it(): void
    {
        $this->actingFresh($this->employee)
            ->post(route('overtime.store'), ['work_date' => '2026-03-02', 'hours' => '3', 'project_id' => $this->lekki->id, 'reason' => 'Concrete pour runs late.', 'submit_now' => '1'])
            ->assertSessionHasNoErrors();
        $overtime = $this->latestOvertime();
        $this->assertSame('submitted', $overtime->statusKey());

        // Not before it is approved.
        $this->actingFresh($this->employee)->post(route('overtime.worked', $overtime), ['worked_hours' => '2'])->assertForbidden();

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $this->pendingStage('overtime_request', $overtime)), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
        $overtime = $this->reloadOvertime($overtime);
        $this->assertSame('approved', $overtime->statusKey());

        // A second request for the same day is refused.
        $this->actingFresh($this->employee)
            ->post(route('overtime.store'), ['work_date' => '2026-03-02', 'hours' => '1', 'reason' => 'More.', 'submit_now' => '1'])
            ->assertSessionHasErrors('status_id');

        $this->actingFresh($this->employee)->post(route('overtime.worked', $overtime), ['worked_hours' => '3.5'])->assertSessionHasErrors('worked_hours');
        $this->actingFresh($this->stranger)->post(route('overtime.worked', $overtime), ['worked_hours' => '2'])->assertForbidden();
        $this->actingFresh($this->employee)->post(route('overtime.worked', $overtime), ['worked_hours' => '2.5'])->assertSessionHasNoErrors();

        $overtime = $this->reloadOvertime($overtime);
        $this->assertSame('2.50', (string) $overtime->worked_hours);
        $this->assertSame('2.50', (string) $overtime->payableHours());

        // Once worked, it cannot be quietly cancelled.
        $this->actingFresh($this->employee)->post(route('overtime.cancel', $overtime), ['reason' => 'Changed my mind.'])->assertForbidden();
    }

    #[Test]
    public function cancelling_submitted_overtime_closes_its_route(): void
    {
        $this->actingFresh($this->employee)
            ->post(route('overtime.store'), ['work_date' => '2026-03-04', 'hours' => '2', 'reason' => 'Night shift cover.', 'submit_now' => '1'])
            ->assertSessionHasNoErrors();
        $overtime = $this->latestOvertime();
        $stage = $this->pendingStage('overtime_request', $overtime);

        $this->actingFresh($this->stranger)->post(route('overtime.cancel', $overtime), ['reason' => 'x'])->assertForbidden();
        $this->actingFresh($this->employee)->post(route('overtime.cancel', $overtime), ['reason' => 'Cover found.'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $this->reloadOvertime($overtime)->statusKey());
        $this->assertNotSame(WorkflowStageInstance::STATUS_PENDING, $this->withinOrganization($this->org, fn () => $stage->fresh()->status));
    }

    #[Test]
    public function overtime_for_somebody_else_needs_overtime_manage_and_never_on_a_strangers_project(): void
    {
        $this->actingFresh($this->stranger)
            ->post(route('overtime.store'), ['employee_id' => $this->employee->employee->id, 'work_date' => '2026-03-04', 'hours' => '2', 'reason' => 'x'])
            ->assertForbidden();

        $this->actingFresh($this->employee)
            ->post(route('overtime.store'), ['work_date' => '2026-03-04', 'hours' => '2', 'reason' => 'x', 'project_id' => $this->abuja->id])
            ->assertForbidden();

        $this->actingFresh($this->hr)
            ->post(route('overtime.store'), ['employee_id' => $this->employee->employee->id, 'work_date' => '2026-03-04', 'hours' => '2', 'reason' => 'Standby.'])
            ->assertSessionHasNoErrors();

        $overtime = $this->latestOvertime();
        $this->assertSame($this->employee->id, (int) $overtime->requester_id);
        $this->actingFresh($this->stranger)->get(route('overtime.show', $overtime))->assertForbidden();
        $this->actingFresh($this->stranger)->put(route('overtime.update', $overtime), ['work_date' => '2026-03-04', 'hours' => '9', 'reason' => 'x'])->assertForbidden();
    }

    // ================================================================== tenancy and screens

    #[Test]
    public function another_tenant_gets_a_404(): void
    {
        $sheet = $this->openWeek($this->employee);
        $this->actingFresh($this->employee)->post(route('attendance.clock-in'))->assertSessionHasNoErrors();
        $this->actingFresh($this->employee)
            ->post(route('overtime.store'), ['work_date' => '2026-03-04', 'hours' => '2', 'reason' => 'x'])
            ->assertSessionHasNoErrors();
        $record = $this->withinOrganization($this->org, fn () => AttendanceRecord::query()->firstOrFail());
        $overtime = $this->latestOvertime();

        $other = $this->makeOrganization('Kestrel Time');
        $this->provisionConfiguration($other);
        $outsider = $this->makePerson($other, 'Ada Kestrel', null, [
            $this->makeRole($other, 'HR', [
                'timesheet.view' => 'organization', 'timesheet.edit' => 'organization',
                'attendance.view' => 'organization', 'attendance.edit' => 'organization',
                'overtime.view' => 'organization', 'overtime.manage' => 'organization', 'overtime.submit' => 'organization',
            ]),
        ]);

        $this->actingFresh($outsider)->get(route('timesheets.show', $sheet))->assertNotFound();
        $this->actingFresh($outsider)->get(route('timesheets.edit', $sheet))->assertNotFound();
        $this->actingFresh($outsider)->get(route('attendance.edit', $record))->assertNotFound();
        $this->actingFresh($outsider)->get(route('overtime.show', $overtime))->assertNotFound();
        $this->actingFresh($outsider)->post(route('overtime.cancel', $overtime), ['reason' => 'x'])->assertNotFound();
        $this->actingFresh($outsider)->get(route('attendance.index'))->assertOk()->assertDontSee('Tobi Okafor');
    }

    #[Test]
    public function every_time_screen_renders(): void
    {
        $sheet = $this->submittedSheet();
        $this->actingFresh($this->employee)->post(route('attendance.clock-in'), ['project_id' => $this->lekki->id])->assertSessionHasNoErrors();
        $this->actingFresh($this->employee)
            ->post(route('overtime.store'), ['work_date' => '2026-03-04', 'hours' => '2', 'reason' => 'Standby.'])
            ->assertSessionHasNoErrors();
        $overtime = $this->latestOvertime();
        $record = $this->withinOrganization($this->org, fn () => AttendanceRecord::query()->firstOrFail());

        $this->actingFresh($this->employee);
        $this->get(route('attendance.index'))->assertOk()->assertSee('Clock out');
        $this->get(route('timesheets.index'))->assertOk()->assertSee($sheet->number);
        $this->get(route('timesheets.show', $sheet))->assertOk()->assertSee('Lekki Pumping Station');
        $this->get(route('overtime.index'))->assertOk()->assertSee($overtime->number);
        $this->get(route('overtime.create'))->assertOk();
        $this->get(route('overtime.edit', $overtime))->assertOk();
        $this->get(route('overtime.show', $overtime))->assertOk()->assertSee('Submit for approval');

        $this->actingFresh($this->manager);
        $this->get(route('timesheets.show', $sheet))->assertOk()->assertSee('Decide in My Approvals');
        $this->get(route('attendance.index'))->assertOk()->assertSee('Tobi Okafor')->assertSee('Clock in')->assertDontSee('>Correct<', false);

        $this->actingFresh($this->hr);
        $this->get(route('attendance.index'))->assertOk()->assertSee('Record a missed shift');
        $this->get(route('attendance.create'))->assertOk()->assertSee('Tobi Okafor');
        $this->get(route('attendance.edit', $record))->assertOk();
        $this->get(route('timesheets.index', ['view' => 'all']))->assertOk()->assertSee($sheet->number);

        $draft = $this->openWeek($this->employee, '2026-03-09');
        $this->actingFresh($this->employee)->get(route('timesheets.edit', $draft))->assertOk()->assertSee('Save and submit');
    }

    // ================================================================== helpers

    private function openWeek(User $user, string $week = '2026-03-02'): Timesheet
    {
        $this->actingFresh($user)
            ->post(route('timesheets.store'), ['week' => $week])
            ->assertSessionHasNoErrors();

        return $this->withinOrganization($this->org, fn () => Timesheet::query()->latest('id')->firstOrFail()->load('status'));
    }

    private function submittedSheet(): Timesheet
    {
        $sheet = $this->openWeek($this->employee);
        $this->actingFresh($this->employee)
            ->put(route('timesheets.update', $sheet), ['lines' => [$this->line($this->lekki, ['2026-03-02' => '8', '2026-03-03' => '8'])], 'submit_now' => '1'])
            ->assertSessionHasNoErrors();

        return $this->reload($sheet);
    }

    /**
     * @param  array<string,string>  $hours
     * @return array<string,mixed>
     */
    private function line(Project $project, array $hours): array
    {
        return ['project_id' => $project->id, 'description' => 'Site works', 'hours' => $hours];
    }

    private function reload(Timesheet $sheet): Timesheet
    {
        return $this->withinOrganization($this->org, fn () => $sheet->fresh(['status']));
    }

    private function latestOvertime(): OvertimeRequest
    {
        return $this->withinOrganization($this->org, fn () => OvertimeRequest::query()->latest('id')->firstOrFail()->load('status'));
    }

    private function reloadOvertime(OvertimeRequest $overtime): OvertimeRequest
    {
        return $this->withinOrganization($this->org, fn () => $overtime->fresh(['status']));
    }

    private function pendingStage(string $type, Model $subject): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', $type)->where('subject_id', $subject->getKey()))
            ->firstOrFail());
    }
}
