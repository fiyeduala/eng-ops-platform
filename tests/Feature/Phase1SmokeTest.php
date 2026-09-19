<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\TaskChecklistService;
use App\Services\TaskDependencyService;
use App\Services\TaskService;
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

            // Not a model — the status editor takes a module key in the path. Named here so the
            // configuration screens join all three passes, including the one that checks an
            // account holding nothing at all is refused. 'task' is chosen because the provisioner
            // seeds it with several statuses and several moves, so the page renders with rows.
            'entityType' => 'task',
        ]);

        // A project, so the delivery pages join all three passes with a real record rather than
        // being skipped for want of a bound parameter. Created through the service, which seats
        // the administrator on the team — the project page's team panel is therefore exercised
        // with a row in it.
        $project = $this->makeProject($this->org, $this->administrator, 'Marina Bridge Rehabilitation', [
            'org_unit_id' => $department->id,
            'manager_id' => $this->administrator->id,
        ]);

        $this->records['project'] = $project;

        // A task on that project, with a checklist line and a blocker, so the task page joins all
        // three passes with every panel populated — an empty-state render would prove far less.
        $task = $this->makeTask($this->org, $this->administrator, 'Survey the eastern abutment', [
            'project_id' => $project->id,
            'org_unit_id' => $department->id,
            'assignee_id' => $colleague->id,
            'due_on' => now()->addWeek()->toDateString(),
        ]);

        $blocker = $this->makeTask($this->org, $this->administrator, 'Obtain the road closure permit', [
            'project_id' => $project->id,
            'org_unit_id' => $department->id,
        ]);

        $this->withinOrganization($this->org, function () use ($task, $blocker, $colleague) {
            app(TaskChecklistService::class)->add(
                $task,
                ['title' => 'Photograph the existing spalling'],
                $this->administrator,
            );

            app(TaskDependencyService::class)->add($task, $blocker, $this->administrator);

            app(TaskService::class)->subscribe($task, (int) $colleague->id, 'manual', $this->administrator);
        });

        $this->records['task'] = $task;

        // The communication group, each left in the state that renders the most of its own pages.
        //
        // The memo stays a DRAFT, and that is a statement about the module rather than a shortcut:
        // an issued memo is deliberately not editable by anybody, however permissioned, so
        // `memos.edit` answers 403 to the administrator here and would be right to. The issued
        // state and its distribution list are exercised in MemoAccessTest, where the refusal can be
        // asserted rather than tripped over.
        $this->records['memo'] = $this->makeMemo($this->org, $this->administrator, 'Revised setting-out for the eastern abutment', [
            'project_id' => $project->id,
            'org_unit_id' => $department->id,
        ], [$colleague]);

        $announcement = $this->makeAnnouncement($this->org, $this->administrator, 'Site induction moves to Monday mornings', [
            'org_unit_id' => $department->id,
            'urgency' => 'high',
        ]);

        $this->records['announcement'] = $this->publishAnnouncement($this->org, $announcement, $this->administrator);

        $this->records['meeting'] = $this->makeMeeting($this->org, $this->administrator, 'Weekly progress review', [
            'project_id' => $project->id,
            'org_unit_id' => $department->id,
        ], [$colleague]);
    }

    /**
     * EVERY page, not every page up to the first broken one.
     *
     * This assertion used to fail inside the loop, which meant one broken template hid all the
     * others and each full-suite run — fifteen minutes — bought exactly one fix. Two unrelated
     * inventory faults were found that way, one run apiece, and the second was only visible once
     * the first was gone. Collecting the failures and asserting on the collection turns a queue of
     * runs into a list you can read at once.
     */
    #[Test]
    public function every_page_renders_for_someone_who_holds_the_permission(): void
    {
        $broken = [];

        foreach ($this->pages() as $name => $url) {
            $status = $this->actingAs($this->administrator)->get($url)->status();

            if ($status === 200) {
                continue;
            }

            // A redirect is allowed, but only to somewhere that renders. Some entries in the menu
            // are signposts rather than screens — "Escalation rules" lands on the workflow group of
            // the settings page, because escalation behaviour is a setting plus the deadlines on
            // each workflow stage, and a second editor for it would be the start of a second
            // approval path (§78). What this test is really asserting is that every route a person
            // can reach from the navigation puts a page in front of them, so following the redirect
            // and demanding 200 at the end of it is the same assertion, not a weaker one: a 302
            // into a 403, or into another redirect that never settles, still fails here.
            if ($status === 302) {
                $landed = $this->actingAs($this->administrator)->followingRedirects()->get($url)->status();

                if ($landed === 200) {
                    continue;
                }

                $broken[] = $name.' ('.$url.') redirected, and the destination returned '.$landed;

                continue;
            }

            $broken[] = $name.' ('.$url.') returned '.$status;
        }

        $this->assertSame([], $broken, count($broken).' page(s) did not render for an administrator '
            ."holding every permission:\n  ".implode("\n  ", $broken));
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
