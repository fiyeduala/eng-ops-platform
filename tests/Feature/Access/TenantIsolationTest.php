<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use App\Support\PermissionCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Gate 1, tested the way it will actually be attacked (§75, §84, §94).
 *
 * Every assertion here is an HTTP request, not a query. A scope that holds in a unit test and
 * leaks through a controller has not held. The attack modelled throughout is the simplest real
 * one: an administrator of one company — someone with every permission their own tenant can
 * grant — edits the id in the URL.
 *
 * 404, not 403, is the correct answer. 403 would confirm the record exists.
 */
final class TenantIsolationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private User $meridianAdmin;

    private array $kestrelRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->kestrel = $this->makeOrganization('Kestrel Facilities');

        // An administrator of Meridian, holding everything Meridian can grant, at the widest reach
        // the access model has. If tenant isolation can be beaten at all, it is beaten from here.
        $division = $this->makeUnit($this->meridian, 'Engineering', OrgUnitType::Division);

        $this->meridianAdmin = $this->makePerson(
            $this->meridian,
            'Ada Okonkwo',
            $division,
            [$this->makeRole($this->meridian, 'Administrator', $this->everything())],
        );

        $this->kestrelRecords = $this->buildKestrel();
    }

    // ------------------------------------------------------------------ record-level IDOR

    #[Test]
    public function an_administrator_cannot_open_another_organizations_records_by_id(): void
    {
        $attempts = [
            'org-units.show' => $this->kestrelRecords['unit'],
            'org-units.edit' => $this->kestrelRecords['unit'],
            'employees.show' => $this->kestrelRecords['employee'],
            'employees.edit' => $this->kestrelRecords['employee'],
            'users.show' => $this->kestrelRecords['user'],
            'users.edit' => $this->kestrelRecords['user'],
            'roles.show' => $this->kestrelRecords['role'],
            'roles.edit' => $this->kestrelRecords['role'],
            'positions.edit' => $this->kestrelRecords['position'],
            'locations.edit' => $this->kestrelRecords['location'],
        ];

        foreach ($attempts as $route => $record) {
            $response = $this->actingAs($this->meridianAdmin)->get(route($route, $record));

            $response->assertNotFound(
                $route.' returned '.$response->status().' for another organization’s record. '
                .'Gate 1 must make the row unreachable, not merely refused.',
            );
        }
    }

    #[Test]
    public function writes_against_another_organizations_records_are_refused(): void
    {
        $unit = $this->kestrelRecords['unit'];
        $user = $this->kestrelRecords['user'];

        $this->actingAs($this->meridianAdmin)
            ->put(route('org-units.update', $unit), ['name' => 'Renamed by an outsider', 'type' => 'department'])
            ->assertNotFound();

        $this->actingAs($this->meridianAdmin)
            ->delete(route('org-units.destroy', $unit))
            ->assertNotFound();

        $this->actingAs($this->meridianAdmin)
            ->put(route('users.update', $user), ['name' => 'Renamed', 'email' => 'x@example.test'])
            ->assertNotFound();

        // The point of asserting on the database as well: a 404 that still wrote would be worse
        // than a 200 that did, because nothing would ever surface it.
        $this->assertSame(
            'Kestrel Head Office',
            $this->freshFromKestrel(OrgUnit::class, $unit->id)->name,
            'Another organization’s unit was renamed by a request that answered 404.',
        );
    }

    #[Test]
    public function a_users_roles_cannot_be_changed_from_outside_their_organization(): void
    {
        $target = $this->kestrelRecords['user'];
        $ourRole = $this->meridianAdmin->roles->first();

        $this->actingAs($this->meridianAdmin)
            ->put(route('users.roles.update', $target), ['roles' => [$ourRole->id]])
            ->assertNotFound();

        $this->assertSame(
            1,
            $this->withinOrganization($this->kestrel, fn () => User::findOrFail($target->id)->roles()->count()),
            'A role from another organization was attached to an account it cannot reach.',
        );
    }

    // ------------------------------------------------------------------ list-level leakage

    #[Test]
    public function listings_never_contain_another_organizations_records(): void
    {
        $pages = ['org-units.index', 'employees.index', 'users.index', 'roles.index', 'positions.index', 'locations.index'];

        foreach ($pages as $page) {
            $response = $this->actingAs($this->meridianAdmin)->get(route($page));

            $response->assertOk();
            $response->assertDontSee('Kestrel', escape: false);
            $response->assertDontSee($this->kestrelRecords['user']->name, escape: false);
            $response->assertDontSee($this->kestrelRecords['employee']->employee_no, escape: false);
        }
    }

    #[Test]
    public function search_obeys_the_same_boundary_as_the_page_itself(): void
    {
        // §61: a search box that answers a question the page would refuse is a leak with a
        // friendlier interface.
        //
        // The assertions deliberately avoid the search TERM itself. The filter form echoes what was
        // typed back into its own input — `value="Rhona"` — so asserting the term is absent would
        // fail on a page that leaked nothing, and, worse, would pass the day someone removed the
        // echo while the leak stayed. What is asserted instead is data only Kestrel holds and the
        // viewer never supplied: an email address and an employee number. Neither can appear by
        // accident.
        $user = $this->kestrelRecords['user'];
        $employee = $this->kestrelRecords['employee'];

        // Three different ways of asking the same forbidden question — by name, by the exact email,
        // by the employee number — because a scope applied to one searchable column and not the
        // others is the shape this bug actually takes.
        foreach (['Rhona', $user->email, $employee->employee_no] as $term) {
            $response = $this->actingAs($this->meridianAdmin)->get(route('users.index', ['q' => $term]));

            $response->assertOk();

            // Every forbidden string EXCEPT the one just typed, which the form echoes back by
            // design. Asserting the echo is absent would be asserting the form is broken.
            foreach (array_diff(['Calder', $user->email, $employee->employee_no], [$term]) as $secret) {
                $response->assertDontSee($secret, escape: false);
            }

            // Positive half of the assertion: the page really did run the search and come back with
            // nothing, rather than erroring into a shape where "absent" means "never rendered".
            $response->assertSee('No accounts to show', escape: false);
        }
    }

    #[Test]
    public function the_audit_trail_shows_one_organizations_history_and_no_others(): void
    {
        $response = $this->actingAs($this->meridianAdmin)->get(route('audit.index'));

        $response->assertOk();
        $response->assertDontSee('Kestrel', escape: false);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Every permission in the catalogue, at organization scope.
     *
     * @return array<string,string>
     */
    private function everything(): array
    {
        $grants = [];

        foreach (PermissionCatalogue::keys() as $key) {
            $grants[$key] = 'organization';
        }

        return $grants;
    }

    /**
     * The other company: one of everything, each named so a leak is unmistakable in an assertion.
     *
     * @return array<string,mixed>
     */
    private function buildKestrel(): array
    {
        $unit = $this->makeUnit($this->kestrel, 'Kestrel Head Office', OrgUnitType::Department);

        $role = $this->makeRole($this->kestrel, 'Kestrel Administrator', ['user.view' => 'organization']);

        $user = $this->makePerson($this->kestrel, 'Rhona Calder', $unit, [$role]);

        return $this->withinOrganization($this->kestrel, fn () => [
            'unit' => $unit,
            'role' => $role,
            'user' => $user,
            'employee' => Employee::where('user_id', $user->id)->firstOrFail(),
            'position' => Position::create([
                'organization_id' => $this->kestrel->id,
                'org_unit_id' => $unit->id,
                'title' => 'Kestrel Facilities Manager',
                'is_active' => true,
            ]),
            'location' => Location::create([
                'organization_id' => $this->kestrel->id,
                'name' => 'Kestrel Depot',
                'is_active' => true,
            ]),
        ]);
    }

    /**
     * Read a Kestrel row back with Kestrel's own context bound, so the assertion sees what is
     * actually stored rather than what Gate 1 would hide from the attacker.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function freshFromKestrel(string $model, int $id)
    {
        return $this->withinOrganization($this->kestrel, fn () => $model::findOrFail($id));
    }
}
