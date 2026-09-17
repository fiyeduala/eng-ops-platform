<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Engines\Access\OrganizationContext;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\Memo;
use App\Models\Organization;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Services\TaskService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
