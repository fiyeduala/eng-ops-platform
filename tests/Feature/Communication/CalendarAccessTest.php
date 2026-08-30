<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The calendar: an aggregation screen, which is the classic hole in an otherwise sound model (§90).
 *
 * Every screen that gathers several kinds of record in one place is a chance to answer the access
 * question once, loosely, for all of them — and the resulting leak is invisible, because the page
 * looks exactly the same whether or not it is filtering correctly. So the tests below are about the
 * ABSENCE of things, and each one is written so that a single wrong ability in CalendarController
 * makes it fail:
 *
 *   a meeting is on this page only for somebody holding `meeting.view` AND reaching that meeting
 *   a task is on it only for somebody holding `task.view` AND reaching that task
 *   a milestone is on it only for somebody holding `project.view` AND reaching that project
 *
 * The last theme is honesty. A source the viewer cannot see contributes nothing, and the page says
 * so in the legend rather than rendering a month that merely looks quiet — a calendar that silently
 * drops a whole category is worse than one that admits it, because the viewer trusts it and plans
 * around it.
 *
 * Everything is dated into the CURRENT month, since that is the window the page opens on.
 */
final class CalendarAccessTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $commercial;

    private User $ada;       // sees all three sources

    private User $femi;      // meetings only

    private User $chidi;     // tasks only

    private User $bola;      // neither meetings nor tasks

    private User $kunle;     // meetings, but only within Commercial

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->commercial = $this->makeUnit($this->org, 'Commercial', OrgUnitType::Department);

        $everything = $this->makeRole($this->org, 'Delivery Manager', [
            'meeting.view' => 'organization',
            'meeting.create' => 'organization',

            // Cancelling is `meeting.edit`, and cancelling is how this fixture produces the one
            // meeting state the calendar renders differently from every other.
            'meeting.edit' => 'organization',

            'task.view' => 'organization',
            'task.create' => 'organization',
            'task.manage' => 'organization',
            'project.view' => 'organization',
            'project.create' => 'organization',
            'user.view' => 'organization',
        ]);

        $meetingsOnly = $this->makeRole($this->org, 'Coordinator', [
            'meeting.view' => 'organization',
        ]);

        $tasksOnly = $this->makeRole($this->org, 'Technician', [
            'task.view' => 'organization',
        ]);

        // Projects but neither meetings nor tasks: enough to work in the system, not enough to be
        // shown a calendar, which is the route's only guard.
        $projectsOnly = $this->makeRole($this->org, 'Estimator', [
            'project.view' => 'organization',
        ]);

        $narrowMeetings = $this->makeRole($this->org, 'Commercial Lead', [
            'meeting.view' => 'department',
        ]);

        $this->ada = $this->makePerson($this->org, 'Ada Okonkwo', $this->civil, [$everything]);
        $this->femi = $this->makePerson($this->org, 'Femi Balogun', $this->civil, [$meetingsOnly]);
        $this->chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [$tasksOnly]);
        $this->bola = $this->makePerson($this->org, 'Bola Adeniyi', $this->civil, [$projectsOnly]);
        $this->kunle = $this->makePerson($this->org, 'Kunle Sanni', $this->commercial, [$narrowMeetings]);
    }

    // ------------------------------------------------------------------ one ability per source

    #[Test]
    public function each_source_is_drawn_with_its_own_ability(): void
    {
        $meeting = $this->meeting();
        $task = $this->task();

        // Meetings only. The task is in the same month, on a project he could not name.
        $this->actingFresh($this->femi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($meeting->number)
            ->assertDontSee($task->number);

        // Tasks only, and the mirror image.
        $this->actingFresh($this->chidi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($task->number)
            ->assertDontSee($meeting->number);

        $this->actingFresh($this->ada)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($meeting->number)
            ->assertSee($task->number);
    }

    /**
     * Holding the ability is not reaching the record.
     *
     * Kunle can see meetings — his grant just stops at Commercial. If the calendar ever fetched its
     * meetings with anything other than the scope compiler, this is the test that would notice: the
     * meeting is in his month, in his company, and in a department he has nothing to do with.
     */
    #[Test]
    public function the_calendar_is_not_a_second_door_onto_a_record(): void
    {
        $meeting = $this->meeting();

        $this->actingFresh($this->kunle)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertDontSee($meeting->number);

        // And the record itself refuses him, which is the same answer given twice — as it should be.
        $this->actingFresh($this->kunle)->get(route('meetings.show', $meeting))->assertForbidden();
    }

    #[Test]
    public function a_source_the_viewer_cannot_see_is_named_rather_than_silently_dropped(): void
    {
        $this->actingFresh($this->femi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('Task due dates')
            ->assertSee('not visible to you');
    }

    #[Test]
    public function somebody_who_deals_in_neither_meetings_nor_tasks_is_refused_the_page(): void
    {
        // Not an empty calendar — no calendar. The page has no permission of its own, so the guard
        // is "you deal in at least one of the things it shows".
        $this->actingFresh($this->bola)->get(route('calendar.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ what belongs on a day

    #[Test]
    public function a_closed_task_stops_occupying_a_day_it_can_no_longer_be_done_on(): void
    {
        $task = $this->task();

        $this->actingFresh($this->chidi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($task->number);

        $this->withinOrganization($this->org, fn () => app(TaskService::class)->transition(
            $task,
            Status::query()->forEntity('task')->where('key', 'cancelled')->firstOrFail(),
            $this->ada,
            'Superseded by the revised survey scope.',
        ));

        $this->actingFresh($this->chidi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertDontSee($task->number);
    }

    /**
     * A cancelled meeting is struck through rather than removed. The distinction matters on a
     * calendar: "that meeting was called off" and "you were never told about it" look identical if
     * the entry simply disappears, and only one of them is true.
     */
    #[Test]
    public function a_cancelled_meeting_is_struck_through_rather_than_removed(): void
    {
        $meeting = $this->meeting();

        $this->withinOrganization($this->org, fn () => $this->moveTo(
            $this->org,
            'meeting',
            $meeting,
            'cancelled',
            $this->ada,
            'The contractor could not attend.',
        ));

        $response = $this->actingFresh($this->femi)->get(route('calendar.index'))->assertOk();

        $response->assertSee($meeting->number);

        // Asserted against the view data rather than the markup: `line-through` is also the class the
        // legend wears for a source this viewer cannot see, so a string match on the rendered page
        // would pass for a meeting that was not struck through at all.
        $entry = collect($response->viewData('entries'))
            ->firstWhere('reference', $meeting->number);

        $this->assertNotNull($entry, 'The cancelled meeting left the calendar entirely.');
        $this->assertTrue($entry['muted'], 'A cancelled meeting should be struck through, not live.');
    }

    #[Test]
    public function a_project_appears_as_its_milestones(): void
    {
        $project = $this->project();

        $this->actingFresh($this->ada)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($project->name.' starts')
            ->assertSee($project->name.' ends');

        // Femi holds no `project.view`, so the same two days are simply empty for him.
        $this->actingFresh($this->femi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertDontSee($project->name.' starts');
    }

    // ------------------------------------------------------------------ the window

    #[Test]
    public function a_month_outside_the_window_shows_nothing_without_erroring(): void
    {
        $meeting = $this->meeting();

        $this->actingFresh($this->femi)
            ->get(route('calendar.index', ['month' => Carbon::now()->addYear()->format('Y-m')]))
            ->assertOk()
            ->assertDontSee($meeting->number);
    }

    #[Test]
    public function a_nonsensical_month_falls_back_to_this_one(): void
    {
        $meeting = $this->meeting();

        // Not a 500. The parameter is user input on a GET route and is parsed defensively.
        $this->actingFresh($this->femi)
            ->get(route('calendar.index', ['month' => 'not-a-month']))
            ->assertOk()
            ->assertSee($meeting->number);
    }

    // ------------------------------------------------------------------ tenancy

    #[Test]
    public function another_organizations_month_never_appears(): void
    {
        $ours = $this->meeting();

        $other = $this->makeOrganization('Kestrel Infrastructure');
        $unit = $this->makeUnit($other, 'Operations', OrgUnitType::Department);

        $role = $this->makeRole($other, 'Coordinator', [
            'meeting.view' => 'organization',
            'meeting.create' => 'organization',
            'user.view' => 'organization',
        ]);

        $stranger = $this->makePerson($other, 'Ruth Iwu', $unit, [$role]);

        $theirs = $this->makeMeeting($other, $stranger, 'Their weekly review', [
            'org_unit_id' => $unit->id,
            'starts_at' => $this->dayOfThisMonth(9),
            'ends_at' => $this->dayOfThisMonth(9, 11),
        ], [$stranger]);

        // Asserted on titles, not numbers. Numbering is per-tenant, so the two meetings almost
        // certainly carry the SAME reference — which is correct, and which would make a test written
        // against `->number` pass or fail for reasons that have nothing to do with the boundary.
        //
        // Organization-wide `meeting.view` is the widest grant there is, and it stops at the tenant
        // boundary — here as an absence rather than a refusal, because a calendar never names what
        // it is not showing (§75).
        $this->actingFresh($this->femi)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($ours->title)
            ->assertDontSee($theirs->title);

        $this->actingFresh($stranger)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee($theirs->title)
            ->assertDontSee($ours->title);
    }

    // ------------------------------------------------------------------ fixture helpers

    private function meeting(): Meeting
    {
        return $this->makeMeeting($this->org, $this->ada, 'Weekly progress review', [
            'org_unit_id' => $this->civil->id,
            'starts_at' => $this->dayOfThisMonth(9),
            'ends_at' => $this->dayOfThisMonth(9, 11),
        ], [$this->ada]);
    }

    private function task(): Task
    {
        return $this->makeTask($this->org, $this->ada, 'Survey the eastern abutment', [
            'org_unit_id' => $this->civil->id,
            'assignee_id' => $this->ada->id,
            'due_on' => $this->dayOfThisMonth(11)->toDateString(),
        ]);
    }

    private function project(): Project
    {
        return $this->makeProject($this->org, $this->ada, 'Marina Bridge Rehabilitation', [
            'org_unit_id' => $this->civil->id,
            'manager_id' => $this->ada->id,
            'planned_start' => $this->dayOfThisMonth(3)->toDateString(),
            'planned_end' => $this->dayOfThisMonth(20)->toDateString(),
        ]);
    }

    /**
     * A date inside the month the calendar opens on.
     *
     * Counted from the first of the month rather than from today, so the fixture lands in the window
     * under test whatever day the suite happens to run on — a `now()->addWeek()` fixture spends four
     * days a month in the following month and fails there for a reason that has nothing to do with
     * access.
     */
    private function dayOfThisMonth(int $day, int $hour = 10): Carbon
    {
        return Carbon::now()->startOfMonth()->addDays($day - 1)->setTime($hour, 0);
    }
}
