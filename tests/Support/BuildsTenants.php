<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Organization\OrganizationProvisioner;
use App\Engines\Organization\OrgUnitService;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures for the access tests.
 *
 * Deliberately small and hand-built rather than a call to DemoSeeder: these tests are about who
 * can see what, and a fixture whose shape is visible in the test file is the only way to read an
 * assertion about reach and know whether it is right.
 *
 * Everything is created inside an explicit organization context. Gate 1 fails closed, so a model
 * created with no context bound would be written with organization_id 0 and then be invisible to
 * everybody — which would make every test pass for the wrong reason.
 */
trait BuildsTenants
{
    protected function context(): OrganizationContext
    {
        return app(OrganizationContext::class);
    }

    /**
     * Run a callback as if serving a request inside this organization.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    protected function withinOrganization(Organization $organization, callable $callback): mixed
    {
        return $this->context()->within($organization, $callback);
    }

    protected function makeOrganization(string $name): Organization
    {
        return $this->context()->withoutScope(fn () => Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'locale' => 'en',
            'is_active' => true,
        ]));
    }

    /**
     * One unit, with its materialized path maintained by the service rather than by hand — the
     * path IS the visibility mechanism, so a fixture that computed it independently would be
     * testing the fixture.
     */
    protected function makeUnit(
        Organization $organization,
        string $name,
        OrgUnitType $type,
        ?OrgUnit $parent = null,
    ): OrgUnit {
        return $this->withinOrganization($organization, fn () => app(OrgUnitService::class)->create([
            'name' => $name,
            'type' => $type->value,
            'parent_id' => $parent?->id,
            'is_active' => true,
        ]));
    }

    /**
     * A role carrying the given grants.
     *
     * @param  array<string,string|array<int,string>>  $grants  permission key => scope(s)
     */
    protected function makeRole(Organization $organization, string $name, array $grants): Role
    {
        return $this->withinOrganization($organization, function () use ($organization, $name, $grants) {
            $role = Role::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(4),
                'level' => 50,
                'is_system' => false,
                'is_active' => true,
            ]);

            $role->syncGrants($grants);

            return $role;
        });
    }

    /**
     * A person: the login account, the personnel record that places them on the org chart, and
     * their roles. All three, because reach is computed from the employee record — an account with
     * no employee has no unit, and therefore no organizational reach at all.
     *
     * @param  array<int,Role|array{0:Role,1:OrgUnit}>  $roles  a role, or a role pinned to a unit
     */
    protected function makePerson(
        Organization $organization,
        string $name,
        ?OrgUnit $unit = null,
        array $roles = [],
        ?Employee $supervisor = null,
    ): User {
        return $this->withinOrganization($organization, function () use ($organization, $name, $unit, $roles, $supervisor) {
            // Assigned attribute by attribute rather than mass-assigned. `is_super_admin`,
            // `must_change_password` and `password_changed_at` are deliberately absent from the
            // model's fillable list — none of them may ever be set from request input — and a
            // fixture that widened $fillable to be convenient would delete that protection for
            // the whole application.
            $user = new User;
            $user->organization_id = $organization->id;
            $user->name = $name;
            $user->email = Str::slug($name).'-'.Str::random(5).'@example.test';
            $user->password = Hash::make('correct-horse-battery-staple');
            $user->is_active = true;
            $user->is_super_admin = false;
            $user->must_change_password = false;
            $user->password_changed_at = now();
            $user->save();

            $employee = Employee::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'employee_no' => 'E'.Str::upper(Str::random(6)),
                'first_name' => Str::before($name, ' ') ?: $name,
                'last_name' => Str::after($name, ' ') ?: 'Person',
                'org_unit_id' => $unit?->id,
                'supervisor_id' => $supervisor?->id,
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'is_active' => true,
            ]);

            foreach ($roles as $entry) {
                [$role, $pin] = is_array($entry) ? $entry : [$entry, null];

                $user->roles()->attach($role->id, [
                    'scope_type' => $pin ? 'org_unit' : null,
                    'scope_id' => $pin?->id,
                ]);
            }

            $organization->bumpPermissionsVersion();

            // Relations are read back through the access engine on the next request; loading them
            // here keeps a stale null from a fixture step masquerading as "no reach".
            return $user->fresh(['roles', 'employee']);
        });
    }

    /**
     * The configuration a project needs to exist: statuses, the moves between them, numbering
     * schemes, and the project type/role catalogues.
     *
     * Called through OrganizationProvisioner rather than reproduced here, because a fixture that
     * invented its own status vocabulary would let StatusMachine's whitelist drift from the one
     * every real tenant gets. Idempotent, so a test may call it more than once.
     */
    protected function provisionConfiguration(Organization $organization): void
    {
        $this->withinOrganization($organization, function () use ($organization) {
            $provisioner = app(OrganizationProvisioner::class);

            $provisioner->seedStatuses($organization);
            $provisioner->seedNumberingSchemes($organization);
            $provisioner->seedProjectCatalogue($organization);
        });

        app(StatusMachine::class)->flush();
    }

    /**
     * A project, created through ProjectService so the number, the opening status and the manager's
     * own membership row come from the same code path the controller uses. A project assembled with
     * Project::create() in a fixture would have no number, no status and — crucially for the access
     * tests — no team, which is the axis half of them are about.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeProject(
        Organization $organization,
        User $actor,
        string $name,
        array $attributes = [],
    ): Project {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => app(ProjectService::class)->create([
            'name' => $name,
            'confidentiality' => Confidentiality::Internal,
            'priority' => 'normal',
            'health' => 'on_track',
            'is_active' => true,
            ...$attributes,
        ], $actor));
    }

    /**
     * A task, created through TaskService for the same reason projects are: the number, the opening
     * status, the assignment stamps and the creator's follower row all come from one code path. A
     * task built with Task::create() would have no status at all, which would make every transition
     * test pass for the wrong reason.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeTask(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Task {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => app(TaskService::class)->create([
            'title' => $title,
            'confidentiality' => Confidentiality::Internal,
            'priority' => 'normal',
            ...$attributes,
        ], $actor));
    }
}
