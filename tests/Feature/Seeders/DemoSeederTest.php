<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Engines\Access\OrganizationContext;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PermissionSeeder;
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

    /** @return array<int,int> the ids the register would show this person */
    private function visibleTaskIds(User $person): array
    {
        return $this->within(
            $this->meridian,
            fn () => Task::visibleTo($person, 'task.view')->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }
}
