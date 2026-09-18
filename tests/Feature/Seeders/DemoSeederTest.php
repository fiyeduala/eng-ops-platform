<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Engines\Access\OrganizationContext;
use App\Models\Announcement;
use App\Models\Applicant;
use App\Models\Approval;
use App\Models\AttendanceRecord;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeExit;
use App\Models\EmployeeMovement;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\Memo;
use App\Models\Organization;
use App\Models\OvertimeRequest;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\WorkflowStageInstance;
use App\Services\EmployeeExitService;
use App\Services\TaskService;
use App\Services\TrainingRequirementService;
use App\Support\Enums\Decision;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The demonstration data, held to what it claims to show.
 *
 * Seed data is usually untested, and usually rots — a column is renamed, the seeder is not run for
 * a fortnight, and the next person to type `migrate:fresh --seed` meets a stack trace instead of a
 * working system. That alone would justify running it here once.
 *
 * The assertions go further than "it ran", because the delivery slice of DemoSeeder was written to
 * demonstrate specific behaviour and a demo that quietly stops demonstrating it is worse than no
 * demo: it becomes a screenshot of a system nobody has checked. So each test below pins one claim —
 * the two companies are genuinely separate, statuses are the tenant's own, a dependency actually
 * blocks, confidentiality actually hides, and a lapsed membership actually lapses.
 *
 * These are the same properties the access tests prove against hand-built fixtures. Proving them
 * again here is not duplication: those tests show the ENGINE is right, and this one shows the data
 * an evaluator will actually open is a fair picture of it.
 */
final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private Organization $meridian;

    private Organization $kestrel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->meridian = Organization::where('slug', 'meridian-eng')->firstOrFail();
        $this->kestrel = Organization::where('slug', 'kestrel-fm')->firstOrFail();
    }

    #[Test]
    public function both_companies_are_seeded_with_delivery_work(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            [$projects, $tasks] = $this->within($organization, fn () => [
                Project::query()->get(),
                Task::query()->get(),
            ]);

            $this->assertGreaterThan(0, $projects->count(), $organization->name.' has no projects.');
            $this->assertGreaterThan(0, $tasks->count(), $organization->name.' has no tasks.');

            // Not a formality. The global scope fails closed, so a row written with no tenant
            // context would carry organization_id 0 and be invisible to everyone — a demo that
            // seeds successfully and then shows nothing at all.
            foreach ($projects->concat($tasks) as $record) {
                $this->assertSame(
                    (int) $organization->id,
                    (int) $record->organization_id,
                    $record->number.' was seeded outside '.$organization->name.'.',
                );
            }
        }
    }

    #[Test]
    public function neither_company_can_see_the_others_work(): void
    {
        // §75, asked of the seeded data rather than of a fixture. Counting inside each context and
        // comparing to the unscoped total is the cheapest way to catch a delivery pass that leaked
        // rows into the wrong tenant — which is exactly what a StatusMachine or service instance
        // shared between the two would produce.
        $meridianTasks = $this->within($this->meridian, fn () => Task::query()->count());
        $kestrelTasks = $this->within($this->kestrel, fn () => Task::query()->count());

        $this->assertSame(
            DB::table('tasks')->count(),
            $meridianTasks + $kestrelTasks,
            'tasks exist that belong to neither demo company.',
        );

        $meridianIds = $this->within($this->meridian, fn () => Project::query()->pluck('id')->all());

        $this->assertSame(
            [],
            $this->within($this->kestrel, fn () => Project::query()->whereIn('id', $meridianIds)->pluck('id')->all()),
            'a Meridian project is readable from inside Kestrel.',
        );
    }

    #[Test]
    public function every_task_carries_a_status_belonging_to_its_own_company(): void
    {
        // The bug this exists to catch is subtle and would look like the state machine being
        // broken: StatusMachine memoizes its vocabulary per entity type only, so one instance
        // reused across both tenants would file Kestrel's tasks under Meridian's statuses. Both
        // companies would still "work"; the words on the badges would belong to the wrong company.
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            [$statusIds, $used] = $this->within($organization, fn () => [
                Status::query()->forEntity('task')->pluck('id')->all(),
                Task::query()->pluck('status_id')->unique()->all(),
            ]);

            foreach ($used as $statusId) {
                $this->assertContains(
                    (int) $statusId,
                    array_map('intval', $statusIds),
                    'a task in '.$organization->name.' points at another company’s status row.',
                );
            }
        }
    }

    #[Test]
    public function the_client_transmittal_cannot_close_while_the_report_it_waits_on_is_in_review(): void
    {
        $transmittal = $this->meridianTask('Transmit the firewater pump vendor data pack to the client');
        $ops = $this->account($this->meridian, 'c.balogun@meridian-eng.example');

        $this->within($this->meridian, function () use ($transmittal, $ops) {
            $this->assertNotEmpty(
                $transmittal->blockingDependencies(),
                'the demo no longer contains a task that is actually held up by another.',
            );

            $done = Status::query()->forEntity('task')->where('key', 'done')->firstOrFail();

            $this->expectException(\DomainException::class);

            app(TaskService::class)->transition($transmittal, $done, $ops);
        });
    }

    #[Test]
    public function an_engineer_on_the_project_sees_its_work_but_not_the_commercial_task(): void
    {
        // Ngozi Eze is on the tank farm job. Everything below is on that same project, so the
        // project axis reaches all of it — and confidentiality is the ONLY thing that differs
        // between the two tasks, which is what makes this a test of Gate 5 rather than of scope.
        $engineer = $this->account($this->meridian, 'n.eze@meridian-eng.example');
        $ops = $this->account($this->meridian, 'c.balogun@meridian-eng.example');

        $drawings = $this->meridianTask('Issue the IFC foundation drawings for Tanks 4 and 5');
        $variation = $this->meridianTask('Prepare the variation narrative for VO-004 — revised tank internals');

        $visible = $this->visibleTaskIds($engineer);

        $this->assertContains((int) $drawings->id, $visible, 'a project engineer cannot see the project’s own work.');
        $this->assertNotContains((int) $variation->id, $visible, 'the restricted commercial task is visible to the engineering team.');

        $this->assertContains(
            (int) $variation->id,
            $this->visibleTaskIds($ops),
            'the restricted task is hidden even from the director who raised it.',
        );
    }

    #[Test]
    public function membership_that_ended_with_the_job_no_longer_reaches_it(): void
    {
        // §12. The assessment finished, its team's assignments carry a past ends_on, and nobody
        // revoked anything. An engineer reaches tasks on the project axis alone, so the project
        // going quiet is what removes them — while the engineering manager, who reaches the same
        // record down the org chart, still sees it.
        $report = $this->meridianTask('Issue the deck assessment report and repair strategy');

        $lapsed = $this->within($this->meridian, fn () => ProjectMember::query()
            ->where('project_id', $report->project_id)
            ->whereNotNull('ends_on')
            ->where('ends_on', '<', now()->toDateString())
            ->count());

        $this->assertGreaterThan(0, $lapsed, 'the demo no longer contains a lapsed project membership.');

        $this->assertNotContains(
            (int) $report->id,
            $this->visibleTaskIds($this->account($this->meridian, 'n.eze@meridian-eng.example')),
            'an engineer still reaches a project their membership ended on.',
        );

        $this->assertContains(
            (int) $report->id,
            $this->visibleTaskIds($this->account($this->meridian, 'f.yusuf@meridian-eng.example')),
            'the engineering manager lost sight of work in their own department.',
        );
    }

    #[Test]
    public function the_delivery_timeline_is_spread_across_the_weeks_before_now(): void
    {
        $stamps = DB::table('activity_logs')
            ->where('organization_id', $this->meridian->id)
            ->whereIn('subject_type', ['project', 'project_member', 'task'])
            ->pluck('created_at');

        $this->assertGreaterThan(1, $stamps->unique()->count(), 'the delivery history all happened in the same second.');
        $this->assertLessThanOrEqual(now()->toDateTimeString(), (string) $stamps->max(), 'the demo contains activity from the future.');
        $this->assertGreaterThan(
            now()->subDays(90)->toDateTimeString(),
            (string) $stamps->min(),
            'the delivery history predates the company being set up.',
        );
    }

    #[Test]
    public function each_company_says_something_as_well_as_doing_something(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            [$memos, $notices, $meetings] = $this->within($organization, fn () => [
                Memo::query()->get(),
                Announcement::query()->get(),
                Meeting::query()->get(),
            ]);

            $this->assertGreaterThan(0, $memos->count(), $organization->name.' has no memos.');
            $this->assertGreaterThan(0, $notices->count(), $organization->name.' has no announcements.');
            $this->assertGreaterThan(0, $meetings->count(), $organization->name.' has no meetings.');

            foreach ($memos->concat($notices)->concat($meetings) as $record) {
                $this->assertSame(
                    (int) $organization->id,
                    (int) $record->organization_id,
                    $record->number.' was seeded outside '.$organization->name.'.',
                );
            }
        }
    }

    #[Test]
    public function a_drafted_memo_is_invisible_to_the_people_it_names(): void
    {
        // The publication curtain, asked of the demo rather than of a fixture. Being on the
        // distribution of a DRAFT grants nothing: the recipient rows exist, and they will become a
        // reach grant the moment it is issued, but until then the memo belongs to its author alone.
        //
        // This is the failure an evaluator would never notice by clicking around — a draft that
        // leaked would simply look like a memo — so it is worth a test on the seeded data itself.
        $closure = $this->meridianMemo('Year-end office closure and skeleton cover arrangements');
        $hrManager = $this->account($this->meridian, 'a.tijani@meridian-eng.example');
        $author = $this->account($this->meridian, 'c.nwachukwu@meridian-eng.example');

        $this->assertNull($closure->issued_at, 'the demo no longer contains an unissued memo.');
        $this->assertGreaterThan(
            0,
            $this->within($this->meridian, fn () => $closure->recipients()->count()),
            'the draft memo names nobody, so it cannot show that naming somebody grants nothing yet.',
        );

        $this->assertContains(
            (int) $hrManager->id,
            $this->within($this->meridian, fn () => $closure->recipients()->pluck('user_id')
                ->map(fn ($id) => (int) $id)->all()),
            'the HR manager is no longer on the draft’s distribution.',
        );

        $this->assertNotContains(
            (int) $closure->id,
            $this->visibleIds(Memo::class, $hrManager, 'memo.view'),
            'a named recipient can read a memo that was never issued.',
        );

        $this->assertContains(
            (int) $closure->id,
            $this->visibleIds(Memo::class, $author, 'memo.view'),
            'the author cannot see their own draft.',
        );
    }

    #[Test]
    public function the_confidential_memo_is_reachable_only_through_the_distribution_it_was_issued_to(): void
    {
        // Gate 5, and the one path through it. No role in the catalogue holds `memo.view_confidential`
        // at all, so the ONLY way to open this memo is the explicit allow row that issuing wrote for
        // each recipient.
        //
        // The contrast is what makes it a test. Daniel Mbeki holds `memo.view` at `assigned` — the
        // narrowest grant there is — and reads it. Chioma Nwachukwu holds `memo.view` across the
        // whole company, the widest ordinary grant, and cannot, because breadth of scope has nothing
        // to do with clearance.
        $variation = $this->meridianMemo('Commercial position on RPDC variation VO-04');
        $onTheList = $this->account($this->meridian, 'd.mbeki@meridian-eng.example');
        $notOnTheList = $this->account($this->meridian, 'c.nwachukwu@meridian-eng.example');

        $this->assertSame('confidential', $variation->confidentiality->value);
        $this->assertNotNull($variation->issued_at, 'the confidential memo was never issued, so no ACL row exists.');

        $this->assertContains(
            (int) $variation->id,
            $this->visibleIds(Memo::class, $onTheList, 'memo.view'),
            'somebody on the distribution of an issued confidential memo cannot open it.',
        );

        $this->assertNotContains(
            (int) $variation->id,
            $this->visibleIds(Memo::class, $notOnTheList, 'memo.view'),
            'company-wide memo.view is reading a confidential memo it was never sent.',
        );
    }

    #[Test]
    public function a_withdrawn_memo_keeps_the_record_of_what_people_were_told(): void
    {
        // §59. Withdrawing is not deleting: four people approved overtime against this for a
        // fortnight, and two of them have a read receipt proving they saw it. The instruction is
        // cancelled; the evidence that it was given is not.
        $overtime = $this->meridianMemo('Interim overtime authorisation limits');
        $hrManager = $this->account($this->meridian, 'a.tijani@meridian-eng.example');

        $this->assertNotNull($overtime->issued_at);
        $this->assertNotEmpty($overtime->withdrawn_reason, 'the memo was withdrawn without saying why.');

        $receipt = $this->within($this->meridian, fn () => $overtime->recipients()
            ->where('user_id', $hrManager->id)->first());

        $this->assertNotNull($receipt, 'the withdrawal removed a name from the distribution.');
        $this->assertNotNull($receipt->read_at, 'the read receipt was lost when the memo was withdrawn.');

        $this->assertContains(
            (int) $overtime->id,
            $this->visibleIds(Memo::class, $hrManager, 'memo.view'),
            'a recipient can no longer see a memo they acted on.',
        );
    }

    #[Test]
    public function uncirculated_minutes_are_readable_by_the_person_who_wrote_them_and_by_nobody_in_the_room(): void
    {
        // Recording is not publishing. Everybody invited to the design review can see that it
        // happened; what they cannot see is a half-written record of what was said, until whoever
        // holds `meeting.circulate` releases it.
        //
        // Note that this is a FIELD rule, not a record rule — which is why the assertion below
        // checks that the piping engineer reaches the MEETING and is still refused the MINUTES.
        $review = $this->meridianMeeting('Engineering design review — tank farm piping squad check');
        $secretary = $this->account($this->meridian, 'l.fashola@meridian-eng.example');
        $attendee = $this->account($this->meridian, 's.afolabi@meridian-eng.example');

        $this->assertTrue($review->hasMinutes(), 'the design review has no minutes to withhold.');
        $this->assertNull($review->minutes_circulated_at, 'the demo no longer contains uncirculated minutes.');

        $this->assertContains(
            (int) $review->id,
            $this->visibleIds(Meeting::class, $attendee, 'meeting.view'),
            'an invited engineer cannot even see that the meeting happened.',
        );

        $this->within($this->meridian, function () use ($review, $secretary, $attendee) {
            $this->assertTrue(
                $review->minutesVisibleTo($secretary),
                'the person who wrote the minutes cannot read them back.',
            );

            $this->assertFalse(
                $review->minutesVisibleTo($attendee),
                'uncirculated minutes are readable by the room.',
            );
        });

        // And the other half of the claim: once released, they are simply part of the meeting.
        $progress = $this->meridianMeeting('Delta Terminal — weekly project progress meeting');
        $siteManager = $this->account($this->meridian, 'k.nwosu@meridian-eng.example');

        $this->assertNotNull($progress->minutes_circulated_at, 'the demo contains no circulated minutes.');

        $this->assertNotSame(
            (int) $progress->minutes_recorded_by,
            (int) $progress->organizer_id,
            'the demo no longer shows minutes written by one person and released by another.',
        );

        $this->within($this->meridian, fn () => $this->assertTrue(
            $progress->minutesVisibleTo($siteManager),
            'circulated minutes are still hidden from somebody who was in the room.',
        ));
    }

    #[Test]
    public function the_meeting_that_produced_work_produced_an_ordinary_task(): void
    {
        // §90. The action arising is not a row in a private meeting_actions table that only the
        // meeting page knows how to read: it is a Task, so it appears on the assignee's list, on the
        // calendar, in the overdue report and in her notifications, none of which were told about
        // meetings.
        $progress = $this->meridianMeeting('Delta Terminal — weekly project progress meeting');
        $action = $this->meridianTask(
            'Reissue the tank 4 ring beam pour card against the revised settlement predictions',
        );
        $assignee = $this->account($this->meridian, 'n.eze@meridian-eng.example');

        $this->assertSame($progress->getMorphClass(), $action->linked_type);
        $this->assertSame((int) $progress->id, (int) $action->linked_id);
        $this->assertSame((int) $assignee->id, (int) $action->assignee_id);

        $this->assertContains(
            (int) $action->id,
            $this->visibleTaskIds($assignee),
            'work raised at a meeting never reached the person it was given to.',
        );

        $this->assertSame(
            (int) $progress->project_id,
            (int) $action->project_id,
            'the action arising landed on a different project from the meeting that raised it.',
        );
    }

    #[Test]
    public function the_communication_history_is_spread_like_the_delivery_history_is(): void
    {
        // Communications are back-dated one record at a time rather than swept, because each one has
        // a date its own text refers to. The property to hold is the same either way: a timeline in
        // which everything happened in one second teaches nothing about Engine 4.
        $stamps = DB::table('activity_logs')
            ->where('organization_id', $this->meridian->id)
            ->whereIn('subject_type', ['memo', 'announcement', 'meeting'])
            ->pluck('created_at');

        $this->assertGreaterThan(0, $stamps->count(), 'the communications left no trail at all.');
        $this->assertGreaterThan(1, $stamps->unique()->count(), 'every memo, notice and meeting happened at once.');
        $this->assertLessThanOrEqual(
            now()->toDateTimeString(),
            (string) $stamps->max(),
            'the demo contains communications from the future.',
        );
    }

    #[Test]
    public function leave_was_decided_by_line_managers_through_the_route_engine(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            $states = $this->within($organization, fn () => LeaveRequest::query()->with('status')->get()
                ->map(fn (LeaveRequest $leave) => $leave->statusKey())->countBy()->all());

            $this->assertArrayHasKey('approved', $states, $organization->name.' has no approved leave.');
            $this->assertArrayHasKey('submitted', $states, $organization->name.' has no leave awaiting a decision.');
            $this->assertArrayHasKey('rejected', $states, $organization->name.' has no rejected leave.');

            // Nothing was approved behind the engine's back: every decided request has a closed route.
            $unrouted = $this->within($organization, fn () => LeaveRequest::query()
                ->whereHas('status', fn ($q) => $q->whereIn('key', ['approved', 'rejected']))
                ->whereDoesntHave('workflowInstances')
                ->count());
            $this->assertSame(0, $unrouted, $organization->name.' has leave decided outside a workflow.');

            $waiting = $this->within($organization, fn () => WorkflowStageInstance::query()->pending()
                ->whereHas('instance', fn ($q) => $q->where('subject_type', 'leave_request'))
                ->count());
            $this->assertGreaterThan(0, $waiting, $organization->name.' has no leave in anybody\'s inbox.');
        }
    }

    #[Test]
    public function hours_were_clocked_booked_and_decided_on_the_same_route_as_everything_else(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            [$shifts, $open, $corrected, $sheets, $overtime, $worked, $waiting] = $this->within($organization, fn () => [
                AttendanceRecord::query()->count(),
                AttendanceRecord::query()->open()->count(),
                AttendanceRecord::query()->whereNotNull('adjusted_by')->count(),
                Timesheet::query()->with('status')->get()->map(fn (Timesheet $s) => $s->statusKey())->countBy()->all(),
                OvertimeRequest::query()->with('status')->get()->map(fn (OvertimeRequest $o) => $o->statusKey())->countBy()->all(),
                OvertimeRequest::query()->whereNotNull('worked_hours')->count(),
                WorkflowStageInstance::query()->pending()
                    ->whereHas('instance', fn ($q) => $q->whereIn('subject_type', ['timesheet', 'overtime_request']))
                    ->count(),
            ]);

            $this->assertGreaterThan(5, $shifts, $organization->name.' has barely any attendance.');
            $this->assertSame(1, $corrected, $organization->name.' shows no shift put right by hand.');
            $this->assertLessThanOrEqual(1, $open, $organization->name.' has people clocked in twice over.');

            $this->assertArrayHasKey('approved', $sheets, $organization->name.' has no approved timesheet.');
            $this->assertArrayHasKey('submitted', $sheets, $organization->name.' has no timesheet awaiting a decision.');
            $this->assertArrayHasKey('draft', $sheets, $organization->name.' has no timesheet still being filled in.');
            $this->assertArrayHasKey('approved', $overtime, $organization->name.' has no approved overtime.');
            $this->assertGreaterThan(0, $worked, $organization->name.' has no overtime with the hours actually worked.');
            $this->assertGreaterThan(0, $waiting, $organization->name.' has no hours in anybody\'s inbox.');

            // Nothing was decided behind the engine's back — the same check leave gets above.
            $unrouted = $this->within($organization, fn () => Timesheet::query()
                ->whereHas('status', fn ($q) => $q->whereIn('key', ['approved', 'rejected']))
                ->whereDoesntHave('workflowInstances')
                ->count());
            $this->assertSame(0, $unrouted, $organization->name.' has timesheets decided outside a workflow.');

            // And no overtime claims more hours than were approved (§ the payroll reads this).
            $overclaimed = $this->within($organization, fn () => OvertimeRequest::query()
                ->whereColumn('worked_hours', '>', 'hours')->count());
            $this->assertSame(0, $overclaimed, $organization->name.' pays for overtime nobody approved.');
        }
    }

    #[Test]
    public function heads_were_asked_for_and_agreed_on_the_same_route_as_everything_else(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            [$heads, $people, $unrouted, $waiting, $hired] = $this->within($organization, fn () => [
                Vacancy::query()->with('status')->get()->map(fn (Vacancy $v) => $v->statusKey())->countBy()->all(),
                Applicant::query()->with('status')->get()->map(fn (Applicant $a) => $a->statusKey())->countBy()->all(),
                Vacancy::query()
                    ->whereHas('status', fn ($q) => $q->whereIn('key', ['open', 'filled']))
                    ->whereDoesntHave('workflowInstances')
                    ->count(),
                WorkflowStageInstance::query()->pending()
                    ->whereHas('instance', fn ($q) => $q->where('subject_type', 'vacancy'))
                    ->count(),
                Applicant::query()->whereNotNull('hired_employee_id')->with('hiredEmployee')->get(),
            ]);

            $this->assertArrayHasKey('filled', $heads, $organization->name.' filled no vacancy.');
            $this->assertArrayHasKey('open', $heads, $organization->name.' has no vacancy taking applications.');
            $this->assertArrayHasKey('submitted', $heads, $organization->name.' has no requisition awaiting a decision.');

            // The whole claim of this chapter: an approved head went through the engine, exactly as
            // a drawing and a timesheet do. Nothing opened itself.
            $this->assertSame(0, $unrouted, $organization->name.' has vacancies approved outside a workflow.');
            $this->assertGreaterThan(0, $waiting, $organization->name.' has no requisition in anybody\'s inbox.');

            $this->assertArrayHasKey('hired', $people, $organization->name.' hired nobody.');
            $this->assertGreaterThan(0, count(array_intersect_key($people, array_flip(['rejected', 'withdrawn']))),
                $organization->name.' turned nobody down, which is not what recruitment looks like.');

            // A hire is a personnel record, and the link back is what answers "where did this
            // person come from" a year later.
            foreach ($hired as $applicant) {
                $this->assertNotNull($applicant->hiredEmployee, $organization->name.' has a hire pointing at no employee.');
                $this->assertSame($organization->id, $applicant->hiredEmployee->organization_id);
                $this->assertNull($applicant->hiredEmployee->user_id,
                    'Hiring wrote a sign-in, which is not its job — the onboarding list asks for one (§6).');
            }

            // The CV is on the private disk, not a made-up path: §67 is about this file in particular.
            $cv = $this->within($organization, fn () => Applicant::query()->whereNotNull('cv_path')->first());
            $this->assertNotNull($cv, $organization->name.' has no applicant with a CV attached.');
            $this->assertTrue(Storage::disk('local')->exists((string) $cv->cv_path), 'The seeded CV is not on the disk.');
        }
    }

    #[Test]
    public function a_started_checklist_is_a_copy_and_is_only_part_done(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            $lists = $this->within($organization, fn () => EmployeeChecklist::query()->with('items')->get());

            $this->assertGreaterThan(0, $lists->count(), $organization->name.' started no joining or leaving list.');

            $joining = $lists->firstWhere('kind', 'onboarding');
            $this->assertNotNull($joining, $organization->name.' has no new starter with a list.');

            // Part done: the open items are what a demo is for, and the closed ones prove ticking works.
            $this->assertGreaterThan(0, $joining->items->whereNotNull('done_at')->count(), 'Nothing on the joining list was done.');
            $this->assertGreaterThan(0, $joining->items->whereNull('done_at')->count(), 'The joining list has nothing left to do.');
            $this->assertNull($joining->completed_at, 'A list with open items has closed itself.');

            // The items belong to the LIST, not to the template: editing the template afterwards
            // must not rewrite what somebody was asked to do (the lifecycle tests prove the rule;
            // this asserts the demo actually carries copies).
            $this->assertSame(
                0,
                $this->within($organization, fn () => DB::table('employee_checklist_items')
                    ->whereNull('employee_checklist_id')->count()),
                'A started list has items pointing at nothing.',
            );
        }
    }

    #[Test]
    public function the_exit_that_could_not_clear_is_the_one_the_asset_register_objects_to(): void
    {
        // `status` is loaded INSIDE the tenant, like everything else here: the global scope fails
        // closed, so a relation touched after the context has gone resolves to nothing at all.
        $blocked = $this->within($this->meridian, fn () => EmployeeExit::query()
            ->whereHas('employee', fn ($q) => $q->where('employee_no', 'MEI-0016'))
            ->with(['employee', 'status'])
            ->firstOrFail());

        $this->assertSame('notified', $blocked->statusKey());
        $this->assertNull($blocked->cleared_at, 'An exit cleared while the leaver still holds company property.');

        // Read out of the registers, not off a ticked box (§84).
        $blockers = $this->within($this->meridian, fn () => app(EmployeeExitService::class)->blockers($blocked->employee));
        $this->assertNotEmpty($blockers, 'Ruth Okafor holds nothing, so the blocked exit demonstrates nothing.');

        $cleared = $this->within($this->meridian, fn () => EmployeeExit::query()
            ->whereHas('employee', fn ($q) => $q->where('employee_no', 'MEI-0008'))
            ->with(['employee', 'status'])
            ->firstOrFail());

        $this->assertSame('clearing', $cleared->statusKey());
        $this->assertNotNull($cleared->cleared_at);

        // Cleared is not finished: her last day has not arrived, and employment has not ended.
        $this->assertNull($cleared->access_revoked_at);
        $this->assertSame('active', $this->within($this->meridian, fn () => $cleared->employee->employment_status));
    }

    #[Test]
    public function the_finished_exit_ended_the_employment_and_switched_the_sign_in_off(): void
    {
        [$exit, $employee] = $this->within($this->kestrel, function () {
            $exit = EmployeeExit::query()
                ->whereHas('employee', fn ($q) => $q->where('employee_no', 'KFM-007'))
                ->with(['employee.user', 'checklist.items', 'status'])
                ->firstOrFail();

            return [$exit, $exit->employee];
        });

        $this->assertSame('completed', $exit->statusKey());
        $this->assertNotNull($exit->access_revoked_at);

        $this->assertSame('exited', $employee->employment_status);
        $this->assertNotNull($employee->exited_on);
        $this->assertFalse((bool) $employee->is_active);

        // The one that matters: the sign-in, off. An exit where the first two are written and this
        // is not is a company that believes somebody has left while their password still works.
        $this->assertNotNull($employee->user, 'The account was deleted — a leaver keeps their account (nothing is erased).');
        $this->assertFalse((bool) $employee->user->is_active, 'Josh Aiken can still sign in after leaving.');

        // And nothing was erased: the personnel record and the leaving list are both still there.
        $this->assertNotNull($exit->checklist);
        $this->assertGreaterThan(0, $exit->checklist->items->count());
    }

    #[Test]
    public function the_training_matrix_says_what_the_job_demands_and_not_only_what_people_hold(): void
    {
        foreach ([$this->meridian, $this->kestrel] as $organization) {
            $scopes = $this->within($organization, fn () => TrainingRequirement::query()->get()->countBy('scope')->all());

            $this->assertArrayHasKey(TrainingRequirement::SCOPE_ORGANIZATION, $scopes,
                $organization->name.' requires no course of everybody.');
            $this->assertArrayHasKey(TrainingRequirement::SCOPE_ORG_UNIT, $scopes,
                $organization->name.' requires no course of a particular department.');
        }

        // Meridian uses the third scope as well. A demo that only ever said "everybody needs this"
        // would show a matrix a spreadsheet could have produced.
        $meridianScopes = $this->within($this->meridian, fn () => TrainingRequirement::query()->get()->countBy('scope')->all());
        $this->assertArrayHasKey(TrainingRequirement::SCOPE_POSITION, $meridianScopes,
            'No Meridian course is required of a particular job.');

        // And the matrix answers what the certificate register cannot. `gaps` counts mandatory
        // courses somebody has never held or has let lapse — a question that can only be asked from
        // the requirement side, because a missing certificate leaves no row behind to find.
        $rows = $this->within($this->meridian, function () {
            $service = app(TrainingRequirementService::class);

            return $service->matrix(
                Employee::query()->active()->with(['position', 'orgUnit'])->get(),
                $service->activeRequirements(),
            );
        });

        $this->assertNotSame([], $rows, 'The Meridian training matrix is empty.');
        $this->assertGreaterThan(0, collect($rows)->sum('gaps'),
            'Every person at Meridian holds every ticket their job demands, which is not what a training matrix is for.');
    }

    #[Test]
    public function an_appraisal_was_signed_off_by_the_reviewers_own_manager_on_the_same_route_as_everything_else(): void
    {
        // There is no approve button on a review, in either company, at any point. The sign-off is a
        // stage in the approvals inbox or it does not happen (§84).
        $this->assertFalse(Route::has('performance-reviews.approve'));

        foreach ([$this->meridian, $this->kestrel] as $organization) {
            $states = $this->within($organization, fn () => PerformanceReview::query()->with('status')->get()
                ->map(fn (PerformanceReview $r) => $r->statusKey())->countBy()->all());

            $this->assertArrayHasKey('completed', $states, $organization->name.' finished no appraisal.');
            $this->assertArrayHasKey('draft', $states, $organization->name.' released every review at once.');

            $unrouted = $this->within($organization, fn () => PerformanceReview::query()
                ->whereHas('status', fn ($q) => $q->whereIn('key', ['completed', 'rejected']))
                ->whereDoesntHave('workflowInstances')
                ->count());
            $this->assertSame(0, $unrouted, $organization->name.' signed off a review outside a workflow.');

            // Acknowledged, which is the half people forget: a review nobody was shown is a rating
            // written about somebody behind their back.
            $acknowledged = $this->within($organization, fn () => PerformanceReview::query()
                ->whereNotNull('acknowledged_at')->count());
            $this->assertGreaterThan(0, $acknowledged, $organization->name.' has no review its subject has seen.');
        }

        // And the decision was not the reviewer's own. Marcus Adeyemi wrote Ngozi Eze's rating; his
        // manager signed it off, which is the entire argument for routing an appraisal at all.
        [$review, $decider] = $this->within($this->meridian, function () {
            $review = PerformanceReview::query()
                ->whereHas('employee', fn ($q) => $q->where('employee_no', 'MEI-0006'))
                ->with(['status', 'workflowInstances.stageInstances.approvals'])
                ->firstOrFail();

            $decision = $review->workflowInstances
                ->flatMap->stageInstances
                ->flatMap->approvals
                ->first(fn (Approval $a) => $a->decision === Decision::Approve);

            return [$review, $decision?->user_id];
        });

        $this->assertSame('completed', $review->statusKey());
        $this->assertNotNull($decider, 'Ngozi Eze\'s review was completed with nobody having decided it.');
        $this->assertNotSame($review->reviewer_id, $decider,
            'The manager who wrote the rating is the one who signed it off.');
    }

    #[Test]
    public function an_approved_movement_has_not_moved_anybody_until_its_date(): void
    {
        $states = $this->within($this->meridian, fn () => EmployeeMovement::query()->with('status')->get()
            ->map(fn (EmployeeMovement $m) => $m->statusKey())->countBy()->all());

        $this->assertArrayHasKey('applied', $states, 'Meridian applied no movement.');
        $this->assertArrayHasKey('approved', $states, 'Meridian has no movement agreed and still to happen.');
        $this->assertArrayHasKey('submitted', $states, 'Meridian has no movement awaiting a decision.');
        $this->assertArrayHasKey('draft', $states, 'Meridian has no movement still being drafted.');

        $this->assertFalse(Route::has('employee-movements.approve'));

        $unrouted = $this->within($this->meridian, fn () => EmployeeMovement::query()
            ->whereHas('status', fn ($q) => $q->whereIn('key', ['approved', 'applied', 'rejected']))
            ->whereDoesntHave('workflowInstances')
            ->count());
        $this->assertSame(0, $unrouted, 'Meridian has movements decided outside a workflow.');

        // The applied one rewrote the personnel record...
        $applied = $this->within($this->meridian, fn () => EmployeeMovement::query()
            ->whereHas('status', fn ($q) => $q->where('key', 'applied'))
            ->with(['employee', 'status'])
            ->firstOrFail());

        $this->assertNotNull($applied->applied_at);
        $this->assertSame($applied->to_org_unit_id, $applied->employee->org_unit_id,
            'A movement is marked applied and the personnel record does not agree with it.');
        $this->assertNotSame([], $applied->changeSummary(), 'An applied movement that changed nothing.');

        // ...and the approved one deliberately has not. This is the row the whole register exists
        // for: the company has agreed, the org chart has not moved, and the nightly sweep is what
        // will move it on the effective date.
        $waiting = $this->within($this->meridian, fn () => EmployeeMovement::query()
            ->whereHas('status', fn ($q) => $q->where('key', 'approved'))
            ->with(['employee', 'status'])
            ->firstOrFail());

        $this->assertNull($waiting->applied_at);
        $this->assertTrue($waiting->effective_on->isFuture(), 'An approved movement is already due and was not applied.');
        $this->assertSame($waiting->from_org_unit_id, $waiting->employee->org_unit_id,
            'An approved movement moved somebody before its effective date.');
        $this->assertSame($waiting->from_supervisor_id, $waiting->employee->supervisor_id,
            'An approved movement changed a reporting line before its effective date.');

        // The before side is a snapshot taken at submission, not a join at read time.
        $this->assertNotNull($waiting->from_org_unit_id, 'A submitted movement carries no "from" snapshot.');
    }

    #[Test]
    public function a_conduct_case_is_not_readable_by_the_people_standing_next_to_it(): void
    {
        $outcomes = $this->within($this->meridian, fn () => DisciplinaryCase::query()->with('status')->get()
            ->map(fn (DisciplinaryCase $c) => $c->statusKey())->countBy()->all());

        $this->assertArrayHasKey('closed', $outcomes, 'Meridian has no case that reached an end.');
        $this->assertArrayHasKey('withdrawn', $outcomes, 'Meridian withdrew nothing — an allegation is sometimes wrong.');
        $this->assertArrayHasKey('hearing', $outcomes, 'Meridian has no case in progress.');

        // The driver's case: heard, decided, appealed, and the sanction REPLACED by the appeal
        // rather than annotated. "Does this person have a live warning" has to read one column.
        $appealed = $this->within($this->meridian, fn () => DisciplinaryCase::query()
            ->whereNotNull('appeal_received_on')
            ->with(['status', 'employee'])
            ->firstOrFail());

        $this->assertSame('verbal_warning', $appealed->outcome, 'The appeal did not change the sanction on the record.');
        $this->assertNotNull($appealed->appeal_outcome);
        $this->assertTrue($appealed->sanctionIsLive(), 'The reduced sanction is not counting.');

        // And nobody around it can read it. Tunde Ogunleye is the driver's own line manager and runs
        // the yard he works out of; the `fleet-manager` template carries no conduct permission at
        // all, so the case about his own driver stops at gate 2 and never reaches his register.
        $fleetManager = $this->account($this->meridian, 't.ogunleye@meridian-eng.example');
        $visible = $this->visibleIds(DisciplinaryCase::class, $fleetManager, 'disciplinary.view');

        $this->assertNotContains($appealed->id, $visible,
            'A driver\'s conduct case is sitting in his own manager\'s register (§68).');

        // The investigator reached exactly the case they were given — and closing it took the key
        // back, which is why they reach nothing now.
        $investigator = $this->account($this->meridian, 'g.ibrahim@meridian-eng.example');
        $this->assertNotContains($appealed->id, $this->visibleIds(DisciplinaryCase::class, $investigator, 'disciplinary.view'),
            'A closed case is still open to the person who investigated it.');

        // The live one is a different story: it is in its investigator's hands right now.
        $live = $this->within($this->meridian, fn () => DisciplinaryCase::query()
            ->whereHas('status', fn ($q) => $q->where('key', 'hearing'))
            ->with('status')
            ->firstOrFail());

        $current = $this->account($this->meridian, 'b.adeleke@meridian-eng.example');
        $this->assertContains($live->id, $this->visibleIds(DisciplinaryCase::class, $current, 'disciplinary.view'),
            'The investigator on a live case cannot read it.');
    }

    #[Test]
    public function both_companies_are_paid_by_one_calculator_holding_two_countries_rules(): void
    {
        // The claim §47 makes, asked of the demo rather than of a fixture. Meridian is on a six-band
        // Nigerian table with a consolidated relief; Kestrel is on a four-band British one whose
        // personal allowance is a nil-rate band and whose National Insurance rate FALLS at the top.
        // Neither company's rows appear in any file under app/. If the calculator had a country in
        // it, one of these two sets of figures would be wrong.
        $meridian = $this->within($this->meridian, fn () => PayComponent::query()->with('bands')->get()->keyBy('code'));
        $kestrel = $this->within($this->kestrel, fn () => PayComponent::query()->with('bands')->get()->keyBy('code'));

        $this->assertCount(6, $meridian['PAYE']->bands, 'Meridian\'s tax table is not the one it was seeded with.');
        $this->assertCount(4, $kestrel['PAYE']->bands, 'Kestrel\'s tax table is not the one it was seeded with.');
        $this->assertTrue($meridian->has('CRA'), 'Meridian has no consolidated relief.');
        $this->assertFalse($kestrel->has('CRA'), 'Kestrel has a Nigerian relief component.');
        $this->assertTrue($kestrel->has('NI'), 'Kestrel has no National Insurance.');
        $this->assertFalse($meridian->has('NI'), 'Meridian is paying British National Insurance.');

        // The falling top band is the one a calculator that assumed progression would get wrong.
        $topTwo = $kestrel['NI']->bands->sortBy('sequence')->slice(1)->values();
        $this->assertGreaterThan(
            (float) $topTwo[1]->rate,
            (float) $topTwo[0]->rate,
            'Kestrel\'s National Insurance no longer falls at the upper limit, so the interesting case is gone.',
        );

        foreach ([[$this->meridian, 'NGN'], [$this->kestrel, 'GBP']] as [$organization, $currency]) {
            $run = $this->within($organization, fn () => PayrollRun::query()
                ->with('status')->orderBy('period_start')->firstOrFail());

            $this->assertSame($currency, $run->currency, $organization->name.' is not being paid in its own currency.');
            $this->assertGreaterThan(0, (float) $run->gross_total, $organization->name.' calculated a payroll worth nothing.');
            $this->assertGreaterThan(0, (float) $run->deduction_total, $organization->name.' deducted nothing from anybody.');
            $this->assertLessThan((float) $run->gross_total, (float) $run->net_total,
                $organization->name.' pays out more than it earns.');
            $this->assertGreaterThan(0, (float) $run->employer_cost_total,
                $organization->name.' records no employer cost, so the run understates what the month cost.');

            // Every payslip adds up on its own terms, which is the arithmetic the totals cache.
            $wrong = $this->within($organization, fn () => Payslip::query()
                ->where('payroll_run_id', $run->getKey())
                ->whereNull('skipped_reason')
                ->get()
                ->filter(fn (Payslip $slip) => bccomp(
                    (string) $slip->net_amount,
                    bcsub((string) $slip->gross_amount, (string) $slip->deduction_amount, 2),
                    2,
                ) !== 0));

            $this->assertCount(0, $wrong, $organization->name.' has payslips whose net is not gross less deductions.');
        }
    }

    #[Test]
    public function the_payroll_was_approved_in_the_same_inbox_as_everything_else_and_then_locked(): void
    {
        // The exit criterion of the whole phase, asked of the data an evaluator will actually open.
        // There is no approve route on a payroll run: the only way this run can be locked is that
        // somebody decided it in the inbox, on the engine that decides drawings and leave.
        $this->assertNull(Route::getRoutes()->getByName('payroll-runs.approve'),
            'A payroll run has grown an approval path of its own.');

        $run = $this->within($this->meridian, fn () => PayrollRun::query()
            ->with('status')->orderBy('period_start')->firstOrFail());

        $this->assertSame('approved', $run->statusKey(), 'Meridian\'s payroll was never approved.');
        $this->assertNotNull($run->locked_at, 'An approved payroll run is still editable.');
        $this->assertGreaterThan(10, (int) $run->payslip_count, 'Meridian paid almost nobody.');

        // Decided by the Managing Director, through an Approval row on a workflow stage — not by a
        // service reaching past the engine to set a status.
        $decisions = $this->within($this->meridian, fn () => Approval::query()
            ->whereHas('stageInstance.instance', fn ($q) => $q
                ->where('subject_type', 'payroll_run')
                ->where('subject_id', $run->getKey()))
            ->with('user')
            ->get());

        $this->assertGreaterThan(0, $decisions->count(), 'The payroll was approved outside the workflow engine.');
        $this->assertSame(Decision::Approve->value, $decisions->first()->decision->value ?? $decisions->first()->decision,
            'The decision on the payroll was not an approval.');

        // And Kestrel's is still somebody's decision to make, so the demo shows both halves.
        $waiting = $this->within($this->kestrel, fn () => WorkflowStageInstance::query()->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', 'payroll_run'))
            ->count());

        $this->assertGreaterThan(0, $waiting, 'Kestrel\'s payroll is in nobody\'s inbox.');

        $kestrelRun = $this->within($this->kestrel, fn () => PayrollRun::query()->with('status')->firstOrFail());
        $this->assertNull($kestrelRun->locked_at, 'Kestrel\'s payroll locked itself without a decision.');
    }

    #[Test]
    public function the_person_with_no_salary_on_record_got_a_payslip_that_says_so(): void
    {
        // The failure this exists to prevent is silent in both directions: pay them zero, or drop
        // them from the run, and the first person to find out is the employee on pay day.
        $skipped = $this->within($this->meridian, fn () => Payslip::query()
            ->whereNotNull('skipped_reason')
            ->with('employee')
            ->get());

        $this->assertGreaterThan(0, $skipped->count(),
            'Nobody in the demo is unpayable, so the skipped payslip is no longer demonstrated.');

        // The seconded colleague, specifically. A run in naira meets a salary in euros and REFUSES
        // to guess a rate — the alternative is the platform paying a figure nobody agreed.
        $this->assertTrue(
            $skipped->contains(fn (Payslip $slip) => str_contains((string) $slip->skipped_reason, 'EUR')),
            'Nobody is paid in a currency this run cannot settle, so that branch is undemonstrated.',
        );

        foreach ($skipped as $slip) {
            $this->assertSame(0.0, (float) $slip->net_amount, 'A skipped payslip is paying somebody.');
            $this->assertNotSame('', trim((string) $slip->skipped_reason),
                'A payslip was skipped without saying why, which is the silent failure itself.');
        }

        // They are still IN the run, which is the point — a headcount that quietly shrinks is how
        // somebody goes unpaid for a month without anybody noticing.
        $run = $this->within($this->meridian, fn () => PayrollRun::query()->orderBy('period_start')->firstOrFail());
        $employees = $this->within($this->meridian, fn () => Employee::query()->where('is_active', true)->count());

        $this->assertGreaterThanOrEqual($employees - 2, (int) $run->payslip_count,
            'The run is missing people who were employed during the period.');
    }

    #[Test]
    public function neither_company_can_reach_the_others_pay(): void
    {
        // §75 asked of the most sensitive table in the system. Counted inside each tenant, because
        // the global scope fails closed and a count from nowhere would pass for the wrong reason.
        [$meridianSlips, $meridianSalaries] = $this->within($this->meridian, fn () => [
            Payslip::query()->pluck('id')->all(),
            EmployeeSalary::query()->count(),
        ]);

        [$kestrelSlips, $kestrelSalaries] = $this->within($this->kestrel, fn () => [
            Payslip::query()->pluck('id')->all(),
            EmployeeSalary::query()->count(),
        ]);

        $this->assertGreaterThan(0, $meridianSalaries, 'Nobody at Meridian is on a salary.');

        // A salary is a history, not a current figure, and the history has exactly one open row per
        // person. Two open rows would make "what was she on in March" a question the database
        // answers by whichever row it happened to return first.
        $doubleOpen = $this->within($this->meridian, fn () => EmployeeSalary::query()
            ->whereNull('effective_to')
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('count(*) > 1')
            ->get());

        $this->assertCount(0, $doubleOpen, 'Somebody at Meridian is on two salaries at once.');

        $withHistory = $this->within($this->meridian, fn () => EmployeeSalary::query()
            ->whereNotNull('effective_to')->count());

        $this->assertGreaterThan(0, $withHistory,
            'Every salary in the demo is the original one, so the history the register exists to show is empty.');

        $this->assertGreaterThan(0, $kestrelSalaries, 'Nobody at Kestrel is on a salary.');
        $this->assertSame([], array_intersect($meridianSlips, $kestrelSlips),
            'A payslip is visible from both companies.');

        // Bank details likewise, and stored the way they are meant to be: the number is encrypted
        // at rest, so the raw column must not contain anything a reader could use.
        $accounts = $this->within($this->meridian, fn () => EmployeeBankAccount::query()->get());
        $this->assertGreaterThan(0, $accounts->count(), 'Nobody at Meridian has bank details.');

        $raw = DB::table('employee_bank_accounts')->where('id', $accounts->first()->id)->value('account_number');
        $this->assertNotSame($accounts->first()->account_number, $raw,
            'A bank account number is sitting in the database in the clear.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @template T
     *
     * @param  \Closure():T  $callback
     * @return T
     */
    private function within(Organization $organization, \Closure $callback)
    {
        return app(OrganizationContext::class)->within($organization, $callback);
    }

    /**
     * Looked up INSIDE the tenant, because the global scope fails closed: the same query run from
     * nowhere compiles to `organization_id = 0` and finds nobody. That is the isolation guarantee
     * working, and a helper that reached for withoutGlobalScopes() to get around it here would be
     * teaching the wrong habit in a file about access.
     */
    private function account(Organization $organization, string $email): User
    {
        return $this->within($organization, fn () => User::where('email', $email)->firstOrFail());
    }

    private function meridianTask(string $title): Task
    {
        return $this->within($this->meridian, fn () => Task::query()->where('title', $title)->firstOrFail());
    }

    private function meridianMemo(string $subject): Memo
    {
        return $this->within($this->meridian, fn () => Memo::query()->where('subject', $subject)->firstOrFail());
    }

    private function meridianMeeting(string $title): Meeting
    {
        return $this->within($this->meridian, fn () => Meeting::query()->where('title', $title)->firstOrFail());
    }

    /** @return array<int,int> the ids the register would show this person */
    private function visibleTaskIds(User $person): array
    {
        return $this->within(
            $this->meridian,
            fn () => Task::visibleTo($person, 'task.view')->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /**
     * The same question the index screen asks, for whichever record type is under test.
     *
     * @param  class-string<Model>  $model
     * @return array<int,int>
     */
    private function visibleIds(string $model, User $person, string $permission, ?Organization $organization = null): array
    {
        return $this->within(
            $organization ?? $this->meridian,
            fn () => $model::visibleTo($person, $permission)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }
}
