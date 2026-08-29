<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
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
 * Every Phase 1 page, actually requested.
 *
 * Three passes over the same list of routes, because a page can fail in three independent ways
 * and only the first is obvious:
 *
 *   1. it renders at all, with real rows rather than an empty state
 *   2. it refuses an authenticated user who lacks the permission — server-side (§6)
 *   3. it refuses an anonymous request
 *
 * The second pass is the one worth having. Hiding a link is a courtesy; the route is the control,
 * and an unlinked route is exactly the one nobody remembers to protect.
 *
 * The routes are enumerated from the router rather than listed here by hand, so a page added in a
 * later phase joins all three passes the day it is registered — including the pass that would
 * otherwise never have been written for it.
 */
final class Phase1SmokeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $administrator;

    /** @var array<string,mixed> route name => bound parameter */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $division = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $department = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $division);

        $role = $this->makeRole($this->org, 'Administrator', $this->everything());

        $this->administrator = $this->makePerson($this->org, 'Ada Okonkwo', $department, [$role]);

        // A second person, so every listing has more than the viewer on it and the templates are
        // exercised with a real row rather than their empty state.
        $colleague = $this->makePerson($this->org, 'Tobias Renner', $department);

        $this->records = $this->withinOrganization($this->org, fn () => [
            'org_unit' => $department,
            'employee' => Employee::where('user_id', $colleague->id)->firstOrFail(),
            'user' => $colleague,
            'role' => $role,
            'position' => Position::create([
                'organization_id' => $this->org->id,
                'org_unit_id' => $department->id,
                'title' => 'Senior Structural Engineer',
                'is_active' => true,
            ]),
            'location' => Location::create([
                'organization_id' => $this->org->id,
                'name' => 'Lagos Design Office',
                'is_active' => true,
            ]),
            'audit_log' => AuditLog::query()->latest('id')->firstOrFail(),
        ]);
    }

    #[Test]
    public function every_page_renders_for_someone_who_holds_the_permission(): void
    {
        foreach ($this->pages() as $name => $url) {
            $response = $this->actingAs($this->administrator)->get($url);

            $this->assertSame(
                200,
                $response->status(),
                $name.' ('.$url.') returned '.$response->status().' for an administrator holding every permission.',
            );
        }
    }

    #[Test]
    public function every_permission_gated_page_refuses_someone_who_does_not_hold_it(): void
    {
        // An ordinary account: real, active, inside the tenant, and granted nothing. Not a guest —
        // a guest is refused by the auth middleware and proves nothing about authorization.
        $nobody = $this->makePerson($this->org, 'Wale Adeyemi', null);
        $gated = 0;

        foreach ($this->pages() as $name => $url) {
            if ($this->requiredPermissions($name) === []) {
                continue;   // the dashboard, the profile, the password form: no permission gates them
            }

            $gated++;
            $response = $this->actingAs($nobody)->get($url);

            $this->assertSame(
                403,
                $response->status(),
                $name.' ('.$url.') returned '.$response->status().' for an account holding no permissions at all.',
            );
        }

        $this->assertGreaterThan(20, $gated, 'the route enumeration stopped finding gated pages — check pages()');
    }

    #[Test]
    public function every_authenticated_page_refuses_an_anonymous_request(): void
    {
        foreach ($this->pages() as $name => $url) {
            $response = $this->get($url);

            $this->assertTrue(
                $response->isRedirect(route('login')),
                $name.' ('.$url.') answered '.$response->status().' to an anonymous request instead of sending it to log in.',
            );
        }
    }

    #[Test]
    public function an_account_owing_a_password_change_cannot_reach_anything_else(): void
    {
        // Set on the model, never through a form: a first sign-in with an administrator-issued
        // password has to end at the password screen, not merely be advised to visit it (§92).
        $user = $this->administrator;
        $this->withinOrganization($this->org, fn () => User::whereKey($user->id)->update(['must_change_password' => true]));

        $fresh = $this->withinOrganization($this->org, fn () => User::findOrFail($user->id));

        $this->actingAs($fresh)->get(route('users.index'))->assertRedirect(route('password.edit'));
        $this->actingAs($fresh)->get(route('password.edit'))->assertOk();
    }

    #[Test]
    public function the_dashboard_renders_for_someone_with_no_permissions(): void
    {
        // The landing page after sign-in. If it needed a permission to render, an account granted
        // nothing yet would meet a 403 at the door rather than an empty but working home page —
        // and a new hire's first experience of the system would be an error.
        $nobody = $this->makePerson($this->org, 'Ngozi Eze', null);

        $this->actingAs($nobody)->get(route('dashboard'))->assertOk();
    }

    // ------------------------------------------------------------------ route enumeration

    /**
     * Every named GET page behind authentication, as name => URL.
     *
     * @return array<string,string>
     */
    private function pages(): array
    {
        $pages = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            // Guest-only pages (login, password reset) and the signed file route answer a
            // different question and are covered by the authentication tests.
            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            $parameters = [];
            $skip = false;

            foreach ($route->parameterNames() as $parameter) {
                if (! array_key_exists($parameter, $this->records)) {
                    $skip = true;   // a route whose subject this fixture does not build yet
                    break;
                }

                $parameters[$parameter] = $this->records[$parameter];
            }

            if (! $skip) {
                $pages[$name] = route($name, $parameters);
            }
        }

        ksort($pages);

        return $pages;
    }

    /**
     * The permissions a route's middleware demands, if any.
     *
     * @return array<int,string>
     */
    private function requiredPermissions(string $name): array
    {
        $route = app('router')->getRoutes()->getByName($name);

        foreach ($route?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                return explode(',', substr($middleware, strlen('permission:')));
            }
        }

        return [];
    }

    /** @return array<string,string> */
    private function everything(): array
    {
        $grants = [];

        foreach (PermissionCatalogue::keys() as $key) {
            $grants[$key] = 'organization';
        }

        return $grants;
    }
}
