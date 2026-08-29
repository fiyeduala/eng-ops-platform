<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Engines\Access\AccessResolver;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccessScope;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Gates 0, 2 and 3: what a grant actually reaches (docs/04-access-model.md).
 *
 * One organization, one org tree, one subject per unit. Every test then grants `employee.view` at
 * exactly one scope and asserts, by id, which subjects come back — because "department scope
 * works" is not a claim anybody can check, while "a department viewer reaches Civil, Structures
 * and Bridges, and not Mechanical" is.
 *
 * The tree, with the viewer always sitting in Bridges:
 *
 *   Engineering (division)
 *     ├─ Civil (department)
 *     │    └─ Structures (section)
 *     │         └─ Bridges (team)     ← every viewer lives here
 *     └─ Mechanical (department)
 *   Operations (division)
 *
 * plus one subject with no unit at all, who is reachable only organization-wide. That last one is
 * deliberate: `whereIn(org_unit_id, [...])` silently drops NULL rows, so a person between postings
 * disappears from every scoped list. Better to know it, and to have said so in a test, than to
 * discover it when somebody's leave request cannot be seen by anyone.
 */
final class ScopeResolutionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    /** @var array<string,OrgUnit> */
    private array $units = [];

    /** @var array<string,Employee> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $structures = $this->makeUnit($this->org, 'Structures', OrgUnitType::Section, $civil);
        $bridges = $this->makeUnit($this->org, 'Bridges', OrgUnitType::Team, $structures);
        $mechanical = $this->makeUnit($this->org, 'Mechanical', OrgUnitType::Department, $engineering);
        $operations = $this->makeUnit($this->org, 'Operations', OrgUnitType::Division);

        $this->units = compact('engineering', 'civil', 'structures', 'bridges', 'mechanical', 'operations');

        foreach ($this->units as $slug => $unit) {
            $this->subjects[$slug] = $this->subject('Subject '.ucfirst($slug), $unit);
        }

        $this->subjects['unplaced'] = $this->subject('Subject Unplaced', null);
    }

    // ------------------------------------------------------------------ the ladder, in SQL

    #[Test]
    public function own_scope_reaches_the_viewers_own_record_and_nothing_else(): void
    {
        $viewer = $this->viewer('Own Viewer', AccessScope::Own);

        // Employee::accessOwnerColumns() is ['user_id'], so "own" on a personnel record means the
        // record that describes you. A General Employee granted employee.view at `own` gets their
        // own profile — which is exactly the self-service case — and no colleague's.
        $this->assertSame([], $this->reachedSubjects($viewer));
        $this->assertContains($this->employeeIdOf($viewer), $this->reach($viewer));
    }

    #[Test]
    public function team_scope_reaches_the_viewers_own_team_only(): void
    {
        $viewer = $this->viewer('Team Viewer', AccessScope::Team);

        $this->assertSame(['bridges'], $this->reachedSubjects($viewer));
    }

    #[Test]
    public function section_scope_reaches_the_section_and_the_teams_beneath_it(): void
    {
        $viewer = $this->viewer('Section Viewer', AccessScope::Section);

        // Bridges comes along because the scope resolves to a SUBTREE, not to one unit. A section
        // head who could see the section but not the teams inside it would be a section head in
        // name only.
        $this->assertSame(['bridges', 'structures'], $this->reachedSubjects($viewer));
    }

    #[Test]
    public function department_scope_walks_up_past_the_viewers_own_unit(): void
    {
        $viewer = $this->viewer('Department Viewer', AccessScope::Department);

        // The viewer sits in a team, four levels down. `department` still means Civil: the anchor
        // is found by walking UP the materialized path to the nearest ancestor of that type, not
        // by looking at where the viewer happens to sit. Mechanical is a sibling department and
        // stays out.
        $this->assertSame(['bridges', 'civil', 'structures'], $this->reachedSubjects($viewer));
    }

    #[Test]
    public function division_scope_reaches_sibling_departments_but_not_a_sibling_division(): void
    {
        $viewer = $this->viewer('Division Viewer', AccessScope::Division);

        $this->assertSame(
            ['bridges', 'civil', 'engineering', 'mechanical', 'structures'],
            $this->reachedSubjects($viewer),
        );
    }

    #[Test]
    public function organization_scope_reaches_everyone_including_the_unplaced(): void
    {
        $viewer = $this->viewer('Organization Viewer', AccessScope::Organization);

        $this->assertSame(
            ['bridges', 'civil', 'engineering', 'mechanical', 'operations', 'structures', 'unplaced'],
            $this->reachedSubjects($viewer),
        );
    }

    #[Test]
    public function a_person_with_no_unit_is_invisible_to_every_scope_below_organization(): void
    {
        // Stated as its own test because it is a real operational hazard rather than a design
        // choice: someone between postings, or newly hired before their unit is set, vanishes
        // from every departmental listing at once. The system behaves this way deliberately —
        // failing closed — but somebody has to know.
        foreach ([AccessScope::Team, AccessScope::Section, AccessScope::Department, AccessScope::Division] as $scope) {
            $viewer = $this->viewer('Viewer '.$scope->value, $scope);

            $this->assertNotContains(
                'unplaced',
                $this->reachedSubjects($viewer),
                $scope->value.' scope reached a person who belongs to no unit',
            );
        }
    }

    #[Test]
    public function assigned_scope_covers_direct_reports_as_well_as_the_viewers_own_record(): void
    {
        $viewer = $this->viewer('Supervisor', AccessScope::Assigned);
        $supervisorEmployee = $this->employeeFor($viewer);

        // Deliberately placed in a DIFFERENT division. Reporting lines and the org tree are not
        // the same structure — a matrixed report is the ordinary case in project engineering —
        // and `assigned` has to follow the reporting line, not the tree.
        $report = $this->subject('Direct Report', $this->units['operations'], $supervisorEmployee);

        $reached = $this->reach($viewer);

        $this->assertContains($report->id, $reached, 'a supervisor cannot see their own direct report');
        $this->assertContains($supervisorEmployee->id, $reached, '`assigned` must still include `own`');
        $this->assertNotContains($this->subjects['operations']->id, $reached, 'assigned reached a colleague of the report');
    }

    // ------------------------------------------------------------------ union and narrowing

    #[Test]
    public function two_roles_grant_the_union_of_their_reach_not_the_narrower_of_the_two(): void
    {
        $viewer = $this->viewer('Narrow Viewer', AccessScope::Team);

        $this->attach($viewer, $this->makeRole($this->org, 'Wide Role', ['employee.view' => 'division']));

        // Revoking the narrow role must never be how an administrator widens someone. If the
        // resolver intersected instead of unioning, adding a role would REMOVE access — the least
        // predictable behaviour an access system can have.
        $this->assertSame(
            ['bridges', 'civil', 'engineering', 'mechanical', 'structures'],
            $this->reachedSubjects($viewer),
        );
    }

    #[Test]
    public function pinning_a_role_assignment_narrows_every_grant_it_carries(): void
    {
        $role = $this->makeRole($this->org, 'Department Administrator', ['employee.view' => 'organization']);

        // The role says `organization`. The ASSIGNMENT pins it to Mechanical. The pin wins, and it
        // is what makes one role definition reusable as "administrator of whichever department you
        // were given" instead of forcing a separate role per department (§10).
        $viewer = $this->makePerson(
            $this->org,
            'Pinned Administrator',
            $this->units['bridges'],
            [[$role, $this->units['mechanical']]],
        );

        $this->assertSame(['mechanical'], $this->reachedSubjects($viewer));
    }

    #[Test]
    public function a_pin_narrows_without_removing_reach_granted_by_another_role(): void
    {
        $viewer = $this->viewer('Team Lead', AccessScope::Team);

        $pinned = $this->makeRole($this->org, 'Mechanical Administrator', ['employee.view' => 'organization']);
        $this->attach($viewer, $pinned, $this->units['mechanical']);

        // Their own team, plus the department they were pinned into. Neither grant contains the
        // other, so both predicates have to survive into the compiled query.
        $this->assertSame(['bridges', 'mechanical'], $this->reachedSubjects($viewer));
    }

    #[Test]
    public function the_project_axis_fails_closed_without_touching_the_organizational_one(): void
    {
        // Employees are not project-attached (Employee::accessProjectColumn() returns null), so a
        // `project` grant on this model selects nothing. The assertion that matters is the second
        // one: adding a scope that reaches nothing must not SUBTRACT from a scope that does.
        // Compiling the two into an AND instead of an OR would do exactly that, and it would look
        // like a permissions bug in whichever module noticed first.
        $projectOnly = $this->viewer('Consultant', AccessScope::Project);
        $this->assertSame([], $this->reachedSubjects($projectOnly));

        $both = $this->viewer('Engineer', [AccessScope::Department, AccessScope::Project]);
        $this->assertSame(['bridges', 'civil', 'structures'], $this->reachedSubjects($both));
    }

    // ------------------------------------------------------------------ the list and the page agree

    #[Test]
    public function the_policy_and_the_compiled_list_answer_identically_for_every_record(): void
    {
        // The structural claim ScopedPolicy is built on: Gate 3 re-runs the SAME compiler against a
        // single row, so a record can never be openable while absent from every list, nor listed
        // while unopenable. Asserted across the whole fixture rather than on a happy path, because
        // the two implementations only need to diverge on one row for the guarantee to be worthless.
        foreach ([AccessScope::Own, AccessScope::Team, AccessScope::Department, AccessScope::Organization] as $scope) {
            $viewer = $this->viewer('Agreement '.$scope->value, $scope);
            $listed = $this->reach($viewer);

            foreach ($this->allEmployees() as $employee) {
                $inList = in_array($employee->id, $listed, true);
                $canOpen = Gate::forUser($viewer)->allows('view', $employee);

                $this->assertSame(
                    $inList,
                    $canOpen,
                    sprintf(
                        'At %s scope, employee #%d is %s the list but %s openable.',
                        $scope->value,
                        $employee->id,
                        $inList ? 'in' : 'absent from',
                        $canOpen ? 'is' : 'is not',
                    ),
                );
            }
        }
    }

    // ------------------------------------------------------------------ grants that should not count

    #[Test]
    public function a_deactivated_role_grants_nothing(): void
    {
        $viewer = $this->viewer('Suspended Role Holder', AccessScope::Organization);

        $this->withinOrganization($this->org, function () use ($viewer) {
            Role::whereIn('id', $viewer->roleIds())->update(['is_active' => false]);
            $this->org->bumpPermissionsVersion();
        });

        $this->assertSame([], $this->reachedSubjects($this->refresh($viewer)));
    }

    #[Test]
    public function a_role_assignment_outside_its_dates_grants_nothing(): void
    {
        // Time-bounded assignments are how a secondment ends without anyone remembering to revoke
        // it. If the window were not enforced, "temporary" access would be permanent access with
        // a comment on it.
        $expired = $this->viewer('Finished Secondment', AccessScope::Organization);
        $this->setAssignmentWindow($expired, now()->subYear(), now()->subMonth());

        $future = $this->viewer('Future Secondment', AccessScope::Organization);
        $this->setAssignmentWindow($future, now()->addMonth(), null);

        $current = $this->viewer('Current Secondment', AccessScope::Organization);
        $this->setAssignmentWindow($current, now()->subDay(), now()->addMonth());

        $this->assertSame([], $this->reachedSubjects($this->refresh($expired)), 'an expired assignment still granted access');
        $this->assertSame([], $this->reachedSubjects($this->refresh($future)), 'an assignment that has not started granted access');
        $this->assertNotSame([], $this->reachedSubjects($this->refresh($current)), 'a current assignment granted nothing');
    }

    #[Test]
    public function a_deactivated_account_authorizes_nothing_however_broad_its_roles(): void
    {
        $viewer = $this->viewer('Departed Colleague', AccessScope::Organization);

        $this->withinOrganization($this->org, fn () => User::whereKey($viewer->id)->update(['is_active' => false]));
        $viewer = $this->refresh($viewer);

        $this->assertSame([], $this->reachedSubjects($viewer));
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $this->subjects['bridges']));
    }

    #[Test]
    public function a_suspended_tenant_authorizes_nothing_even_off_the_http_path(): void
    {
        // EnsureOrganizationContext already refuses these requests at the door. This asserts the
        // same answer where the middleware does not run — a queued job, a scheduled report, a
        // console command — because a suspension that only holds in the browser is not one.
        $viewer = $this->viewer('Administrator', AccessScope::Organization);

        $this->assertNotSame([], $this->reachedSubjects($viewer), 'fixture is wrong: the viewer had no reach to lose');

        $this->context()->withoutScope(fn () => Organization::whereKey($this->org->id)->update(['is_active' => false]));
        app(AccessResolver::class)->flushMemo();

        $this->assertSame([], $this->reachedSubjects($viewer));
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $this->subjects['bridges']));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', Employee::class));
    }

    #[Test]
    public function holding_a_neighbouring_permission_does_not_grant_this_one(): void
    {
        // Gate 2 is a lookup of one exact key, never a prefix match. `employee.view_contact` and
        // `employee.view` are different questions about the same record, and the whole reason the
        // catalogue separates them is that most people should hold the first and not the second.
        $viewer = $this->makePerson(
            $this->org,
            'Contact Only',
            $this->units['bridges'],
            [$this->makeRole($this->org, 'Contact Role', ['employee.view_contact' => 'organization'])],
        );

        $this->assertSame([], $this->reachedSubjects($viewer));
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $this->subjects['bridges']));
    }

    // ------------------------------------------------------------------ module-specific rules

    #[Test]
    public function confidential_personnel_fields_need_their_own_permission(): void
    {
        $viewer = $this->viewer('HR Assistant', AccessScope::Organization);
        $own = $this->employeeFor($viewer);

        // Reaching the record is not the same as reading everything on it. Date of birth, national
        // ID and pay band sit behind employee.view_confidential, which this role does not carry.
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $this->subjects['bridges']));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewConfidential', $this->subjects['bridges']));

        // Except on your own record, which you may always read in full.
        $this->assertTrue(Gate::forUser($viewer)->allows('viewConfidential', $own));
        $this->assertTrue(Gate::forUser($viewer)->allows('viewContact', $own));
    }

    #[Test]
    public function nobody_edits_their_own_personnel_record(): void
    {
        // Separation of duties, not a permission question. Unit, position, supervisor and pay band
        // all determine what someone can reach; letting a person edit their own is a self-service
        // escalation route that no scope should be able to open.
        $viewer = $this->viewer('Everything Viewer', AccessScope::Organization);
        $this->attach($viewer, $this->makeRole($this->org, 'Editor', ['employee.edit' => 'organization', 'employee.delete' => 'organization']));

        $own = $this->employeeFor($viewer);

        $this->assertTrue(Gate::forUser($viewer)->allows('update', $this->subjects['bridges']));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $own));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $own));
    }

    #[Test]
    public function permanent_destruction_is_denied_to_everyone(): void
    {
        // `forceDelete` maps to employee.delete_permanently, which no module declares — so the
        // resolver fails closed and nobody, including an account holding every permission in the
        // catalogue at organization scope, can erase a record the audit trail refers to (§59).
        $viewer = $this->viewer('Everything Viewer', AccessScope::Organization);
        $this->attach($viewer, $this->makeRole($this->org, 'Destroyer', ['employee.delete' => 'organization']));

        $this->assertFalse(Gate::forUser($viewer)->allows('forceDelete', $this->subjects['bridges']));
    }

    // ------------------------------------------------------------------ fixtures and helpers

    /** A person with no roles: someone to be seen, not someone who sees. */
    private function subject(string $name, ?OrgUnit $unit, ?Employee $supervisor = null): Employee
    {
        $user = $this->makePerson($this->org, $name, $unit, [], $supervisor);

        return $this->employeeFor($user);
    }

    /**
     * A viewer in Bridges holding `employee.view` at the given scope or scopes.
     *
     * @param  AccessScope|array<int,AccessScope>  $scopes
     */
    private function viewer(string $name, AccessScope|array $scopes): User
    {
        $values = array_map(
            fn (AccessScope $s) => $s->value,
            is_array($scopes) ? $scopes : [$scopes],
        );

        return $this->makePerson(
            $this->org,
            $name,
            $this->units['bridges'],
            [$this->makeRole($this->org, $name.' Role', ['employee.view' => $values])],
        );
    }

    private function attach(User $user, Role $role, ?OrgUnit $pin = null): void
    {
        $this->withinOrganization($this->org, function () use ($user, $role, $pin) {
            $user->roles()->attach($role->id, [
                'scope_type' => $pin ? 'org_unit' : null,
                'scope_id' => $pin?->id,
            ]);

            $this->org->bumpPermissionsVersion();
        });
    }

    private function setAssignmentWindow(User $user, ?\DateTimeInterface $from, ?\DateTimeInterface $to): void
    {
        $this->withinOrganization($this->org, function () use ($user, $from, $to) {
            DB::table('role_user')->where('user_id', $user->id)->update([
                'starts_at' => $from?->format('Y-m-d'),
                'ends_at' => $to?->format('Y-m-d'),
            ]);

            $this->org->bumpPermissionsVersion();
        });
    }

    /**
     * Ids of every employee the viewer may see, through the same call an index page makes.
     *
     * @return array<int,int>
     */
    private function reach(User $viewer): array
    {
        return $this->withinOrganization(
            $this->org,
            fn () => Employee::visibleTo($viewer, 'employee.view')->pluck('id')->map(intval(...))->all(),
        );
    }

    /**
     * The same reach, expressed as the fixture slugs it contains — so a failure message names
     * "mechanical" rather than "#14", and reads as the sentence the test is actually about.
     *
     * @return array<int,string>
     */
    private function reachedSubjects(User $viewer): array
    {
        $reached = $this->reach($viewer);

        $slugs = [];

        foreach ($this->subjects as $slug => $employee) {
            if (in_array($employee->id, $reached, true)) {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    /** @return array<int,Employee> */
    private function allEmployees(): array
    {
        return $this->withinOrganization($this->org, fn () => Employee::query()->get()->all());
    }

    private function employeeFor(User $user): Employee
    {
        return $this->withinOrganization(
            $this->org,
            fn () => Employee::where('user_id', $user->id)->firstOrFail(),
        );
    }

    private function employeeIdOf(User $user): int
    {
        return $this->employeeFor($user)->id;
    }

    /**
     * Re-read a user after their roles, dates or account state changed.
     *
     * The relations are reloaded, not just the row: roleIds() reads the loaded relation when there
     * is one, so a stale `roles` collection would have the test asserting against the grants the
     * user held a moment ago.
     */
    private function refresh(User $user): User
    {
        app(AccessResolver::class)->flushMemo();

        return $this->withinOrganization(
            $this->org,
            fn () => User::with(['roles', 'employee'])->findOrFail($user->id),
        );
    }
}
