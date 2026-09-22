<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Export;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use App\Support\PermissionCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Every route declares a gate, and the ones that do not are named here with a reason (§6).
 *
 * THIS TEST EXISTS BECAUSE OF WHAT PHASE 8 ENDED ON. Three call sites asked for a permission holder
 * by a column that does not exist, matched nobody, and passed every suite in this repository for two
 * phases — because a suite made of denials stays green when the thing it guards has stopped working.
 * The same reasoning applies one level up: a route with no gate on it is not a failing test anywhere.
 * It renders, it returns 200, and every test written about it passes. Nothing goes red. The only way
 * to find it is to ask the router directly, which is what this file does.
 *
 * `Phase1SmokeTest` requests pages and checks the answers, and is the better test where it reaches.
 * It reaches 205 of the 377 authenticated GET routes — the rest carry a parameter its fixture cannot
 * bind and are skipped, silently, which is the same failure shape again — and it does not touch the
 * 580 write routes at all, because it enumerates GET only. A page that renders correctly for the
 * right person and a POST that deletes the record for anybody are the same module and only one of
 * them is being asked about.
 *
 * So this test asks a weaker question of ALL 957 routes rather than a strong question of some:
 * does a gate exist. It cannot tell whether the gate is the RIGHT one — `permission:task.view` on a
 * finance screen would pass here — and it is not trying to. It is the assertion that catches a route
 * shipped with no middleware at all, on the day it is added, which is the failure nothing else in
 * this codebase would notice.
 *
 * @see CrossTenantRouteTest for the strong question, asked of every route a fixture can bind.
 */
final class RouteGateTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    /**
     * The authenticated routes that carry NO `permission:` middleware, each with why.
     *
     * Every one of these is gated by OWNERSHIP instead, which middleware cannot express: there is
     * no permission that means "your own notifications", because the answer depends on the record
     * rather than the person. A permission gate on `notifications.open` would be strictly worse than
     * none — it would let every holder of it open everybody's.
     *
     * The list is exhaustive and the test below fails if the router grows a nineteenth. That is the
     * point: adding a route with no gate should require a developer to come here and write down
     * which of the two reasons applies, not merely to forget.
     *
     * @var array<string,string>
     */
    private const PERSONAL_ROUTES = [
        // Reachable by anyone signed in, and showing only that person's own things.
        'dashboard' => 'the landing page; composes only what the viewer may see, and must render for a new account holding nothing',
        'profile.show' => 'the viewer, editing themselves',
        'profile.update' => 'the viewer, editing themselves',
        'password.edit' => 'the viewer, changing their own password',
        'password.update' => 'the viewer, changing their own password',
        'logout' => 'ending your own session',
        'search' => 'every result is re-checked through visibleTo(), so the gate is per record, not per route (§61)',
        'notifications.index' => 'your own notifications',
        'notifications.preferences' => 'your own delivery preferences',
        'notifications.preferences.update' => 'your own delivery preferences',
        'notifications.read-all' => 'your own notifications',
        'training-requirements.mine' => 'your own training, which you may see without holding the training register',
        'exports.index' => 'your own exports; an export is a frozen copy of one person\'s visibility',

        // Ownership of a SPECIFIC record — the policy is the gate, and these are the ones an IDOR
        // probe goes for first. Each is exercised below rather than merely listed.
        'notifications.open' => 'ownership: NotificationPolicy',
        'notifications.read' => 'ownership: NotificationPolicy',
        'exports.download' => 'ownership: ExportPolicy, plus `signed` — and the signature is not the gate, see below',
        'exports.destroy' => 'ownership: ExportPolicy',
        'tasks.unfollow' => 'ownership: you may always stop following something yourself',
    ];

    private Organization $org;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $division = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $department = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $division);

        $grants = [];

        foreach (PermissionCatalogue::keys() as $key) {
            $grants[$key] = 'organization';
        }

        $this->administrator = $this->makePerson(
            $this->org,
            'Ada Okonkwo',
            $department,
            [$this->makeRole($this->org, 'Administrator', $grants)],
        );
    }

    #[Test]
    public function every_authenticated_route_either_demands_a_permission_or_is_declared_personal(): void
    {
        $ungated = [];

        foreach ($this->authenticatedRoutes() as $name => $route) {
            if ($this->permissionsDemandedBy($name) !== []) {
                continue;
            }

            if (array_key_exists($name, self::PERSONAL_ROUTES)) {
                continue;
            }

            $ungated[] = $route['methods'].'  '.$name.'  ['.$route['uri'].']';
        }

        sort($ungated);

        $this->assertSame([], $ungated, count($ungated).' route(s) are behind authentication but '
            ."demand no permission and are not declared personal in PERSONAL_ROUTES.\n"
            .'Add the middleware, or add the route to that list with the ownership rule that gates it '
            ."instead:\n  ".implode("\n  ", $ungated));
    }

    /**
     * The declaration is a list of real routes, not a list of names that used to be routes.
     *
     * A stale entry here is not harmless. It is a permanent hole in the test above, sitting under
     * the name of something that no longer exists, ready for a future route to be given the same
     * name and inherit an exemption nobody chose for it.
     */
    #[Test]
    public function the_personal_route_declaration_has_no_stale_entries(): void
    {
        $routes = $this->authenticatedRoutes();
        $stale = [];

        foreach (self::PERSONAL_ROUTES as $name => $reason) {
            if (! array_key_exists($name, $routes)) {
                $stale[] = $name.' — declared personal, but no authenticated route by that name exists';

                continue;
            }

            if ($this->permissionsDemandedBy($name) !== []) {
                $stale[] = $name.' — declared personal, but it now carries permission middleware; remove it from the list';
            }
        }

        $this->assertSame([], $stale, implode("\n  ", $stale));
    }

    /**
     * Every gated route names a permission the catalogue actually contains.
     *
     * The Phase 8 defect in one sentence: a string that looks like a key, compared against something
     * that never holds it, failing open-ended rather than loudly. `permission:document.aprove` is a
     * route nobody can reach and nothing reports — the middleware resolves a permission that does not
     * exist, `hasAny()` is false for everybody including the administrator, and the screen is simply
     * missing. Typos in this position do not throw.
     */
    #[Test]
    public function every_permission_named_in_middleware_exists_in_the_catalogue(): void
    {
        $catalogue = array_flip(PermissionCatalogue::keys());
        $unknown = [];

        foreach ($this->authenticatedRoutes() as $name => $route) {
            foreach ($this->permissionsDemandedBy($name) as $permission) {
                if (! array_key_exists($permission, $catalogue)) {
                    $unknown[] = $name.' demands `'.$permission.'`, which is not in PermissionCatalogue';
                }
            }
        }

        sort($unknown);

        $this->assertSame([], $unknown, count($unknown).' route(s) demand a permission that does not '
            ."exist, and are therefore reachable by nobody at all:\n  ".implode("\n  ", $unknown));
    }

    /**
     * And the reverse: every permission in the catalogue is demanded by something.
     *
     * A permission nothing asks for is a checkbox on the role editor that grants access to nothing —
     * an administrator ticks it, believes they have given somebody a capability, and has not. Not an
     * error, so it is a warning rather than a failure, but it is the kind of drift that makes a
     * permission catalogue stop describing the system it belongs to.
     *
     * Permissions enforced only in a policy or a blade directive are legitimately absent from route
     * middleware, which is why this asserts a proportion rather than zero.
     */
    #[Test]
    public function most_of_the_permission_catalogue_is_reachable_from_a_route(): void
    {
        $demanded = [];

        foreach (array_keys($this->authenticatedRoutes()) as $name) {
            foreach ($this->permissionsDemandedBy($name) as $permission) {
                $demanded[$permission] = true;
            }
        }

        $all = PermissionCatalogue::keys();
        $orphans = array_values(array_diff($all, array_keys($demanded)));

        sort($orphans);

        $this->assertLessThan(
            count($all) * 0.35,
            count($orphans),
            count($orphans).' of '.count($all).' permissions are named by no route middleware. Some are '
            .'legitimately enforced in a policy instead, but this many suggests the catalogue has '
            ."drifted from the routes:\n  ".implode("\n  ", array_slice($orphans, 0, 40)),
        );
    }

    // ------------------------------------------------------------------ the ownership-gated ones

    /**
     * A SIGNED LINK IS NOT A GATE, AND THIS IS THE TEST THAT SAYS SO.
     *
     * `exports.download` carries `signed` middleware, and it would be easy to read that as the
     * authorization — the URL cannot be forged, so only somebody who was sent it can use it. That
     * reasoning is wrong in the way that matters. A signature proves the LINK is authentic. It
     * proves nothing whatever about who is holding it. These links travel by email and by
     * notification, which means they end up in forwarded threads, shared inboxes, helpdesk tickets
     * and phone previews, and every one of those is a second reader with a valid signature.
     *
     * What is behind the link makes that worse rather than better. An export's rows were chosen by
     * `visibleTo($requester)` at the moment of the click, so the file is one person's reach, frozen,
     * with nothing written on it that says whose. A second reader does not get a redacted version —
     * they get somebody else's visibility under a report's full name, either more than they may see
     * or less than they would expect, and in both cases a total they will quote.
     *
     * So the same signed URL is opened twice here, by two accounts. The owner must get the file.
     * The other person must not, and the signature must not save them.
     */
    #[Test]
    public function a_valid_signed_link_still_refuses_a_bearer_who_does_not_own_the_file(): void
    {
        Storage::fake(Export::DISK);

        $intruder = $this->makePerson($this->org, 'Wale Adeyemi', null);

        $path = 'exports/'.$this->org->getKey().'/ada-projects.csv';
        Storage::disk(Export::DISK)->put($path, "code,name\nMB-001,Marina Bridge\n");

        $export = $this->withinOrganization($this->org, fn () => Export::query()->create([
            'organization_id' => $this->org->getKey(),
            'requested_by' => $this->administrator->getKey(),
            'name' => 'Ada’s register export',
            'entity_type' => 'project',
            'format' => 'csv',
            'status' => 'ready',
            'filename' => 'projects.csv',
            'path' => $path,
            'bytes' => Storage::disk(Export::DISK)->size($path),
            'rows' => 1,
            'truncated' => false,
            'started_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]));

        // ONE link, signed once, exactly as the notification would carry it.
        $link = URL::temporarySignedRoute('exports.download', now()->addDay(), ['export' => $export->getKey()]);

        // The owner collects it. Without this the test below would pass just as well against a
        // download route that is broken for everybody — which is the failure this phase exists to
        // stop being invisible.
        $this->actingFresh($this->administrator)->get($link)->assertOk();

        // The same link, a different account, an unexpired signature.
        $response = $this->actingFresh($intruder)->get($link);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'a valid signed export link answered '.$response->status().' to somebody who does not own it.',
        );

        // And deleting somebody else's, which carries no signature at all.
        $this->assertContains(
            $this->actingFresh($intruder)->delete(route('exports.destroy', $export))->status(),
            [403, 404],
            'exports.destroy let somebody delete an export they do not own.',
        );

        $this->assertDatabaseHas('exports', ['id' => $export->getKey()]);
    }

    /**
     * The same question for a notification, which is the other thing sent by link.
     *
     * A notification row is a durable copy of something a record said at a moment in time, and
     * `notifications.open` follows it through to the record. Opening somebody else's is both a
     * disclosure and a read receipt on their behalf.
     */
    #[Test]
    public function opening_somebody_elses_notification_is_refused(): void
    {
        $intruder = $this->makePerson($this->org, 'Ngozi Eze', null);

        // Two, because opening one's own notification MARKS IT READ — so a single row could not tell
        // "the intruder was refused" from "the owner's own open did that". The first proves the
        // route works; the second is the one nobody legitimate ever touches.
        [$opened, $untouched] = $this->withinOrganization($this->org, fn () => [
            Notification::query()->create([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->administrator->getKey(),
                'key' => 'workflow.stalled',
                'title' => 'An approval route stalled',
                'body' => 'The record has stopped and the stage needs reassigning.',
                'urgency' => 'normal',
            ]),
            Notification::query()->create([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->administrator->getKey(),
                'key' => 'approval.overdue',
                'title' => 'Overdue approval: Marina Bridge drawing register',
                'body' => 'The stage was due yesterday and is still waiting on you.',
                'urgency' => 'normal',
            ]),
        ]);

        // The owner opens their own, so the refusals below are a refusal and not a broken route.
        $this->actingFresh($this->administrator)
            ->get(route('notifications.open', $opened))
            ->assertRedirect();

        foreach ([['get', 'notifications.open'], ['post', 'notifications.read']] as [$method, $name]) {
            $response = $this->actingFresh($intruder)->{$method}(route($name, $untouched));

            $this->assertContains(
                $response->status(),
                [403, 404],
                $name.' answered '.$response->status().' for a notification addressed to somebody else.',
            );
        }

        $this->assertNotNull(
            $this->withinOrganization($this->org, fn () => Notification::findOrFail($opened->getKey()))->read_at,
            'opening your own notification did not mark it read — the refusals below prove nothing if the route is simply broken.',
        );

        $this->assertNull(
            $this->withinOrganization($this->org, fn () => Notification::findOrFail($untouched->getKey()))->read_at,
            'a stranger marked somebody else’s notification as read.',
        );
    }

    // ------------------------------------------------------------------ the router, read directly

    /**
     * Every named route behind the auth middleware, as name => {methods, uri}.
     *
     * Read off the router rather than listed here, so a route added in a later phase joins this test
     * the day it is registered. A hand-written list would have exactly the blind spot this file is
     * about.
     *
     * @return array<string,array{methods:string,uri:string}>
     */
    private function authenticatedRoutes(): array
    {
        $routes = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            $routes[$name] = [
                'methods' => implode('|', $methods),
                'uri' => $route->uri(),
            ];
        }

        ksort($routes);

        return $routes;
    }

    /** @return array<int,string> */
    private function permissionsDemandedBy(string $name): array
    {
        $route = app('router')->getRoutes()->getByName($name);

        foreach ($route?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                return explode(',', substr($middleware, strlen('permission:')));
            }
        }

        return [];
    }
}
