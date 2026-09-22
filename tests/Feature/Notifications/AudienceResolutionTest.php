<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Engines\Notification\Audience;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectTeamService;
use App\Services\TaskService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Every branch of Audience::resolve() must find the people it claims to find.
 *
 * THIS FILE EXISTS BECAUSE OF THE SHAPE OF ITS FAILURE, NOT BECAUSE OF ITS SUBJECT. Audience has
 * ten branches, and every one of them is a hand-written query naming tables and columns as
 * strings: `employees.org_unit_id`, `role_user.ends_at`, `org_units.head_employee_id`,
 * `project_members.org_unit_id`. Get one of those names wrong — rename a column, mistype a join,
 * qualify nothing inside a correlated subquery — and the branch does not error. It returns `[]`.
 *
 * `[]` is the problem. It is also exactly what a CORRECT audience returns when the answer really is
 * nobody: a project with no team, a unit with no head, a record nobody follows. So the failure
 * produces no exception, no log line, no red test, and no complaint from a user — because the
 * symptom of this bug is a notification that never arrives, and nobody reports the absence of a
 * message they were never told to expect. It survives indefinitely, and the feature is off.
 *
 * The rest of the suite cannot catch it. NotificationDispatchTest asks the §68 question — can
 * somebody be TOLD what they cannot READ — and its assertions are overwhelmingly about people who
 * must NOT be notified. Every one of those passes faster and more convincingly when the audience
 * has silently resolved to nobody at all. A dead branch makes that file greener, not redder.
 *
 * So the assertions here are equality against a NAMED set, never `assertNotEmpty`. Non-emptiness
 * would prove the query runs; equality proves it runs and answers the question asked. Each branch
 * is then paired with the exclusion that makes its positive mean something: a role that lapsed
 * yesterday, a membership that ended, a person in the other company, a suspended account. A branch
 * that returned everybody would pass the first assertion and fail the second.
 *
 * Also note what is deliberately NOT tested here: permissions. Audience does not check any, by
 * design (§68 is enforced one layer up in NotificationDispatcher), and a test that expected
 * filtering here would be arguing for the second access-decision site the class docblock forbids.
 */
final class AudienceResolutionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $sahel;

    private OrgUnit $engineering;

    private OrgUnit $structural;

    private OrgUnit $hse;

    private Role $engineerRole;

    private User $priya;      // project manager; head of Structural

    private User $lade;       // Structural, technical lead on Alpha, follows the task

    private User $john;       // Structural, reports to Lade, engineer role

    private User $david;      // HSE, on Alpha for HSE

    private User $tunde;      // HSE, on nothing, holds no role, sponsors the project

    private User $lapsed;     // held the engineer role until yesterday

    private User $suspended;  // engineer role, account switched off

    private User $outsider;   // Sahel, and nothing here may ever reach them

    private Project $alpha;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->sahel = $this->makeOrganization('Sahel Infrastructure');

        $this->engineering = $this->makeUnit($this->meridian, 'Engineering', OrgUnitType::Division);
        $this->structural = $this->makeUnit($this->meridian, 'Structural', OrgUnitType::Department, $this->engineering);
        $this->hse = $this->makeUnit($this->meridian, 'HSE', OrgUnitType::Department);

        $managerRole = $this->makeRole($this->meridian, 'Project Manager', [
            'project.view' => 'organization',
            'project_member.assign' => 'organization',
            'task.view' => 'organization',
            'task.create' => 'organization',
            'user.view' => 'organization',
        ]);

        $this->engineerRole = $this->makeRole($this->meridian, 'Engineer', [
            'task.view' => 'assigned',
        ]);

        $this->priya = $this->makePerson($this->meridian, 'Priya Raman', $this->engineering, [$managerRole]);
        $this->lade = $this->makePerson($this->meridian, 'Lade Bakare', $this->structural, [$this->engineerRole]);

        // John reports to Lade. The supervisor axis is an EMPLOYEE pointing at an EMPLOYEE, and
        // `supervisorOf()` has to walk user -> employee -> supervisor -> employee -> user to
        // answer it. Four hops, any one of which returns null quietly.
        $this->john = $this->makePerson(
            $this->meridian,
            'John Adeyemi',
            $this->structural,
            [$this->engineerRole],
            supervisor: $this->employeeOf($this->lade),
        );

        $this->david = $this->makePerson($this->meridian, 'David Nwosu', $this->hse);
        $this->tunde = $this->makePerson($this->meridian, 'Tunde Salami', $this->hse);

        $this->lapsed = $this->makePerson($this->meridian, 'Bisi Okonjo', $this->structural);
        $this->suspended = $this->makePerson($this->meridian, 'Femi Adaora', $this->structural, [$this->engineerRole]);

        $this->outsider = $this->makePerson($this->sahel, 'Halima Yusuf');

        // Held the role, and does not hold it today. Attached by hand because there is no service
        // that back-dates a grant — and because the lapse is the whole point of the row.
        $this->withinOrganization($this->meridian, function (): void {
            $this->lapsed->roles()->attach($this->engineerRole->getKey(), [
                'starts_at' => now()->subYear()->toDateString(),
                'ends_at' => now()->subDay()->toDateString(),
            ]);
        });

        $this->suspended->forceFill(['is_active' => false])->save();

        // Whoever heads Structural. Set directly: the assignment itself has nothing to prove here,
        // only the reading of it back out, which is what `headOf()` does.
        $this->withinOrganization($this->meridian, fn () => DB::table('org_units')
            ->where('id', $this->structural->getKey())
            ->update(['head_employee_id' => $this->employeeOf($this->priya)->getKey()]));

        $this->alpha = $this->makeProject($this->meridian, $this->priya, 'Marina Bridge', [
            'org_unit_id' => $this->engineering->getKey(),
            'manager_id' => $this->priya->getKey(),
            'sponsor_id' => $this->tunde->getKey(),
        ]);

        $this->withinOrganization($this->meridian, function (): void {
            $team = app(ProjectTeamService::class);

            $team->addMember($this->alpha, [
                'user_id' => $this->lade->getKey(),
                'org_unit_id' => $this->structural->getKey(),
                'project_role_id' => $this->projectRole('technical-lead')->getKey(),
            ], $this->priya);

            $team->addMember($this->alpha, [
                'user_id' => $this->david->getKey(),
                'org_unit_id' => $this->hse->getKey(),
                'project_role_id' => $this->projectRole('hse-officer')->getKey(),
            ], $this->priya);

            // Ended yesterday. Still a row, still flagged active, and not on the project today.
            $team->addMember($this->alpha, [
                'user_id' => $this->john->getKey(),
                'org_unit_id' => $this->structural->getKey(),
                'ends_on' => now()->subDay()->toDateString(),
            ], $this->priya);
        });

        $this->task = $this->makeTask($this->meridian, $this->priya, 'Check the abutment reinforcement', [
            'project_id' => $this->alpha->getKey(),
            'org_unit_id' => $this->structural->getKey(),
        ]);

        $this->withinOrganization($this->meridian, fn () => app(TaskService::class)
            ->subscribe($this->task, (int) $this->lade->getKey(), 'watching', $this->priya));
    }

    // ================================================================== the ten branches

    #[Test]
    public function naming_people_resolves_to_those_people(): void
    {
        $this->assertAudience(
            [$this->lade, $this->john],
            Audience::user($this->lade)->orUser($this->john),
        );
    }

    /**
     * A role, by slug — and only the people who hold it TODAY.
     *
     * The date bounds are the interesting half. `role_user.starts_at` and `ends_at` are read here
     * and in the access engine, and if the two ever disagree somebody is notified about a record
     * they can no longer open, or stops hearing about one they still can.
     */
    #[Test]
    public function a_role_resolves_to_the_people_holding_it_today(): void
    {
        $this->assertAudience(
            [$this->lade, $this->john],
            Audience::role($this->engineerRole->slug),
        );
    }

    #[Test]
    public function a_role_does_not_resolve_to_a_grant_that_has_lapsed_or_an_account_switched_off(): void
    {
        $resolved = $this->resolve(Audience::role($this->engineerRole->slug));

        $this->assertNotContains((int) $this->lapsed->getKey(), $resolved,
            'a role grant that ended yesterday is not a role held today.');

        $this->assertNotContains((int) $this->suspended->getKey(), $resolved,
            'a suspended account is not a recipient — very often its mail is why it was suspended.');
    }

    #[Test]
    public function an_inactive_role_resolves_to_nobody_even_though_the_grants_still_exist(): void
    {
        $this->withinOrganization($this->meridian, fn () => $this->engineerRole
            ->forceFill(['is_active' => false])->save());

        $this->assertSame([], $this->resolve(Audience::role($this->engineerRole->slug)));
    }

    /**
     * A unit, and by default everything under it.
     *
     * "Tell Engineering" almost never means the people whose row literally says Engineering. The
     * subtree is walked through the materialized `path`, so this also proves the path is being
     * maintained — a unit created without one would resolve to itself alone and look exactly like
     * a deliberately narrow audience.
     */
    #[Test]
    public function a_unit_resolves_to_its_whole_subtree_by_default(): void
    {
        $this->assertAudience(
            [$this->priya, $this->lade, $this->john, $this->lapsed, $this->suspended],
            Audience::orgUnit($this->engineering),
            withoutSuspended: true,
        );
    }

    #[Test]
    public function a_unit_resolves_to_itself_alone_when_the_subtree_is_declined(): void
    {
        $this->assertAudience(
            [$this->priya],
            Audience::orgUnit($this->engineering, includeSubtree: false),
        );
    }

    /**
     * The project team, plus the two people who are on it whether or not anybody added them.
     *
     * Tunde is the sponsor and holds no team row anywhere. If the principals branch broke, this is
     * the only assertion in the suite that would notice — and the manager and the sponsor are
     * precisely the two people whose silence costs the most.
     */
    #[Test]
    public function a_project_resolves_to_its_team_and_its_principals(): void
    {
        $this->assertAudience(
            [$this->priya, $this->tunde, $this->lade, $this->david],
            Audience::project($this->alpha),
        );
    }

    #[Test]
    public function a_project_does_not_resolve_to_a_membership_that_has_ended(): void
    {
        $this->assertNotContains(
            (int) $this->john->getKey(),
            $this->resolve(Audience::project($this->alpha)),
            'a membership that ended yesterday does not reach the project, so it must not receive '
            .'that project\'s post either.',
        );
    }

    #[Test]
    public function a_project_department_resolves_to_the_people_staffed_for_that_department(): void
    {
        $this->assertAudience(
            [$this->lade],
            Audience::projectDepartment($this->alpha, $this->structural),
        );

        $this->assertAudience(
            [$this->david],
            Audience::projectDepartment($this->alpha, $this->hse),
        );
    }

    #[Test]
    public function a_project_role_resolves_to_whoever_holds_it_on_that_project(): void
    {
        $this->assertAudience(
            [$this->lade],
            Audience::projectRole($this->alpha, 'technical-lead'),
        );
    }

    /**
     * Followers.
     *
     * The most used branch in the application by a distance — thirty-seven call sites — and the one
     * with the most ways to resolve to nobody, because it matches on a MORPH TYPE string. A morph
     * map entry changed without a migration leaves every existing row unfindable and every
     * follower notification in the system silently undelivered.
     */
    #[Test]
    public function followers_resolve_to_the_people_subscribed_to_that_record(): void
    {
        // Priya is here because TaskService subscribes the creator. That is the behaviour, so it is
        // the expectation — a test written around only the explicit subscribe() would have had to
        // be loosened the first time it ran, and loosening is how equality assertions get lost.
        $this->assertAudience(
            [$this->priya, $this->lade],
            Audience::followers($this->task),
        );
    }

    #[Test]
    public function supervisor_of_resolves_to_the_account_one_rung_up(): void
    {
        $this->assertAudience([$this->lade], Audience::supervisorOf($this->john));
    }

    #[Test]
    public function head_of_resolves_to_the_account_of_whoever_heads_the_unit(): void
    {
        $this->assertAudience([$this->priya], Audience::headOf($this->structural));
    }

    #[Test]
    public function the_organization_resolves_to_everybody_in_it_and_nobody_outside_it(): void
    {
        $resolved = $this->resolve(Audience::organization());

        $this->assertContains((int) $this->tunde->getKey(), $resolved);

        $this->assertNotContains((int) $this->outsider->getKey(), $resolved,
            'the other company appeared in an organization-wide audience (§75).');

        $this->assertNotContains((int) $this->suspended->getKey(), $resolved);
    }

    // ================================================================== the guards around them

    /**
     * The final re-pin, which is the one line that makes every branch above safe to be wrong about.
     *
     * `orUser()` takes ids from callers, and a caller reading an id off a related record can hand
     * over another company's user without any branch noticing. The pass at the end of resolve()
     * drops it before a channel is touched.
     */
    #[Test]
    public function a_named_user_from_another_company_is_dropped_at_the_end(): void
    {
        $this->assertAudience(
            [$this->lade],
            Audience::user($this->lade)->orUser($this->outsider),
        );
    }

    #[Test]
    public function exclusion_wins_no_matter_which_order_the_calls_came_in(): void
    {
        $this->assertAudience(
            [$this->david],
            Audience::none()
                ->except($this->lade)
                ->orProjectDepartment($this->alpha, $this->structural)
                ->orProjectDepartment($this->alpha, $this->hse),
        );
    }

    #[Test]
    public function an_audience_with_no_rules_resolves_to_nobody_without_asking_the_database(): void
    {
        $this->assertTrue(Audience::none()->isEmpty());

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([], Audience::none()->resolve((int) $this->meridian->getKey()));

        $this->assertSame([], DB::getQueryLog(),
            'an empty audience queried the database; the early return is what keeps a dispatcher '
            .'looping over records that have no audience from costing a query each.');

        DB::disableQueryLog();
    }

    /**
     * The coverage floor, and the reason this file cannot rot quietly.
     *
     * A branch added to the match in `resolve()` and not tested here is the exact defect this file
     * is about: it would answer nobody forever and nothing would say so. Reading the arms straight
     * out of the source turns "somebody forgot" into a failure.
     */
    #[Test]
    public function every_branch_of_the_resolver_is_covered_by_this_file(): void
    {
        $source = file_get_contents(base_path('app/Engines/Notification/Audience.php'));

        preg_match('/foreach \(\$this->rules as .*?\n        \}/s', (string) $source, $match);

        preg_match_all("/^\\s*'([a-z_]+)' => /m", $match[0] ?? '', $found);

        $branches = array_values(array_diff($found[1] ?? [], ['default']));

        sort($branches);

        $this->assertSame([
            'followers',
            'head_of',
            'org_unit',
            'organization',
            'project',
            'project_department',
            'project_role',
            'role',
            'supervisor_of',
            'users',
        ], $branches, 'Audience::resolve() gained or lost a branch. Every branch answers `[]` when '
            .'it is wrong, which nothing else in this suite can tell apart from a correct refusal, '
            .'so a new one needs a named-set assertion here before it can be trusted.');
    }

    // ================================================================== helpers

    /**
     * @param  array<int,User>  $expected
     */
    private function assertAudience(array $expected, Audience $audience, bool $withoutSuspended = false): void
    {
        $ids = array_map(fn (User $user) => (int) $user->getKey(), $expected);

        if ($withoutSuspended) {
            // resolve() drops suspended accounts in its last pass, so a subtree expectation names
            // the person who is organizationally there and then loses them here — visibly, rather
            // than by being quietly left out of the list above.
            $ids = array_values(array_diff($ids, [(int) $this->suspended->getKey()]));
        }

        sort($ids);

        $resolved = $this->resolve($audience);

        $this->assertSame($ids, $resolved,
            'the audience resolved to '.$this->describe($resolved)
            .' and should have resolved to '.$this->describe($ids).'.');
    }

    /** @return array<int,int> */
    private function resolve(Audience $audience): array
    {
        $ids = $this->withinOrganization(
            $this->meridian,
            fn () => $audience->resolve((int) $this->meridian->getKey()),
        );

        sort($ids);

        return $ids;
    }

    /** @param  array<int,int>  $ids */
    private function describe(array $ids): string
    {
        if ($ids === []) {
            return 'NOBODY';
        }

        return implode(', ', User::query()->withoutGlobalScopes()->whereKey($ids)
            ->orderBy('id')->pluck('name')->all());
    }

    private function employeeOf(User $user): Employee
    {
        return Employee::query()->withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->firstOrFail();
    }

    private function projectRole(string $slug): ProjectRole
    {
        return ProjectRole::query()->withoutGlobalScopes()
            ->where('organization_id', $this->meridian->getKey())
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
