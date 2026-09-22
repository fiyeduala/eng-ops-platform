<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\DrawingService;
use App\Services\ProjectTeamService;
use App\Services\PurchaseOrderService;
use App\Services\RfqService;
use App\Support\Enums\OrgUnitType;
use App\Support\PermissionCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsEngineering;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsProcurement;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * §75, asked of every URL this fixture can build rather than of one register at a time.
 *
 * `TenantIsolationTest` proves the boundary holds at the query layer: the global scope fails closed,
 * so a model read without a bound tenant compiles to `organization_id = 0`. That is the right place
 * for the proof and it is not in question here. What it cannot tell you is whether every ROUTE
 * actually goes through it. A controller that takes `int $id` and calls `find()` on an unscoped
 * builder, a lookup that runs before the tenant context is bound, a hand-rolled query with a join
 * that reaches past the scope — each of those is a hole the query-layer test cannot see, because the
 * hole is that the query layer was not used.
 *
 * So this asks the application over HTTP, as the attacker would: a real administrator of a real
 * second company, holding EVERY permission in the catalogue, requesting the first company's ids.
 *
 * THE PERMISSIONS ARE THE POINT. It would be easy to write this with an unprivileged intruder and
 * watch it pass, and it would prove almost nothing — a 403 from `permission:` middleware looks
 * identical to a tenant boundary holding, and the middleware would be doing all the work. Granting
 * the intruder everything removes that gate entirely. Every refusal that remains is the tenant
 * boundary itself, with nothing else in front of it.
 *
 * TWO PASSES, AND THE SECOND IS WHY THE FIRST MEANS ANYTHING. A test that only asserts refusals
 * passes just as well against URLs that are malformed, records that were never created, and routes
 * that 404 for everybody — which is exactly the shape of failure this phase exists to stop being
 * invisible. So the same URL list is requested twice: once by the company that owns the records,
 * which must get pages, and once by the company that does not, which must not. Neither pass is
 * evidence without the other.
 */
final class CrossTenantRouteTest extends TestCase
{
    use BuildsAssets, BuildsCommercial, BuildsEngineering, BuildsInventory, BuildsProcurement, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    /**
     * The floor under this test's own coverage.
     *
     * A cross-tenant test that binds nothing passes perfectly. `Phase1SmokeTest` skips any route
     * whose parameter its fixture cannot build — silently, which is how 172 of 377 authenticated GET
     * routes came to be untested without anything going red. This number makes that failure loud
     * here: delete a record from the fixture below and this test fails rather than quietly asking a
     * smaller question.
     *
     * Measured at 248 when written. The floor is set a little under it so that genuinely retiring a
     * route is not a failure, and losing a whole register is.
     */
    private const MINIMUM_BOUND_ROUTES = 240;

    /**
     * Routes that correctly refuse their OWN company, and the record state that makes them do it.
     *
     * The insider pass cannot simply demand 200 everywhere, because some refusals are the
     * application working: an issued purchase order is not editable by anybody, and a native
     * document version has no uploaded file to hand over. Those are 403 and 404 on the merits.
     *
     * But "it refused, so it must be fine" is the reasoning this phase exists to stop, so each one
     * is named here with WHY, and an entry that stops being refused fails
     * `the_declared_state_refusals_are_still_refusals` below. The list can only be shortened by
     * someone who looked.
     *
     * @var array<string,string>
     */
    private const REFUSED_BY_RECORD_STATE = [
        'purchase-orders.edit' => 'the order has been issued; an issued order is not editable (403)',
        'quotations.edit' => 'the quotation won the enquiry; an awarded quotation is frozen (403)',
        'documents.versions.download' => 'the version is native content, so it has no uploaded file to download (404)',
    ];

    private Organization $meridian;

    private Organization $sahel;

    /** Holds every permission, over the records below. */
    private User $insider;

    /** Holds every permission too — in the other company. */
    private User $outsider;

    /** @var array<string,mixed> route parameter name => an Org A record */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Documents and drawings write files. Faked so the fixture cannot leave anything behind.
        Storage::fake('local');

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->sahel = $this->makeOrganization('Sahel Industrial Services');

        $division = $this->makeUnit($this->meridian, 'Engineering', OrgUnitType::Division);
        $civil = $this->makeUnit($this->meridian, 'Civil', OrgUnitType::Department, $division);

        $this->insider = $this->makePerson($this->meridian, 'Ada Okonkwo', $civil, [
            $this->makeRole($this->meridian, 'Administrator', $this->everything()),
        ]);

        // Three people, because the registers insist on it: SeparatesDuties refuses a requisition
        // approved by its own requester and a delivery received by whoever raised the order, so a
        // fixture built by one administrator cannot reach the end of the procurement chain at all.
        $colleague = $this->makePerson($this->meridian, 'Tobias Renner', $civil);
        $storekeeper = $this->makePerson($this->meridian, 'Ngozi Abara', $civil);

        // The other company, built the same way. A real tenant with real structure, not a bare
        // organization row — an outsider whose own company is empty would be refused by accidents
        // of an empty fixture as readily as by the boundary.
        $sahelUnit = $this->makeUnit($this->sahel, 'Operations', OrgUnitType::Division);

        $this->outsider = $this->makePerson($this->sahel, 'Bilal Toure', $sahelUnit, [
            $this->makeRole($this->sahel, 'Administrator', $this->everything()),
        ]);

        $this->records = $this->buildMeridianRecords($civil, $colleague, $storekeeper);
    }

    // ================================================================== the two passes

    #[Test]
    public function every_record_bound_page_renders_for_the_company_that_owns_the_record(): void
    {
        $broken = [];

        foreach ($this->boundPages() as $name => $url) {
            if (array_key_exists($name, self::REFUSED_BY_RECORD_STATE)) {
                continue;
            }

            $status = $this->actingFresh($this->insider)->get($url)->status();

            if ($status === 200) {
                continue;
            }

            if ($status === 302) {
                $landed = $this->actingFresh($this->insider)->followingRedirects()->get($url)->status();

                if ($landed === 200) {
                    continue;
                }

                $broken[] = $name.' ('.$url.') redirected, and the destination returned '.$landed;

                continue;
            }

            $broken[] = $name.' ('.$url.') returned '.$status;
        }

        sort($broken);

        $this->assertSame([], $broken, count($broken).' record-bound page(s) did not render for an '
            .'administrator of the company that owns the record. Until these pass, the cross-tenant '
            ."assertions below are refusals of URLs that do not work for anybody:\n  "
            .implode("\n  ", $broken));
    }

    /**
     * The declared exceptions are still exceptions.
     *
     * Without this, `REFUSED_BY_RECORD_STATE` is a list of routes nobody tests — a permanent hole
     * that grows every time somebody adds a line to make a failure go away. Here an entry that
     * starts passing is itself a failure: the reason it was written down has changed, and somebody
     * has to look at it again.
     */
    #[Test]
    public function the_declared_state_refusals_are_still_refusals(): void
    {
        $pages = $this->boundPages();
        $unexpected = [];

        foreach (self::REFUSED_BY_RECORD_STATE as $name => $reason) {
            $this->assertArrayHasKey($name, $pages, $name.' is declared as refused by record state '
                .'but this fixture no longer binds it, so the declaration is stale.');

            $status = $this->actingFresh($this->insider)->get($pages[$name])->status();

            if (in_array($status, [403, 404], true)) {
                continue;
            }

            $unexpected[] = $name.' now answers '.$status.' to its own company, but is declared: '.$reason;
        }

        $this->assertSame([], $unexpected, "a declared state refusal stopped refusing:\n  "
            .implode("\n  ", $unexpected));
    }

    #[Test]
    public function an_administrator_of_another_company_is_refused_every_record_bound_page(): void
    {
        $leaked = [];

        foreach ($this->boundPages() as $name => $url) {
            $response = $this->actingFresh($this->outsider)->get($url);

            if (in_array($response->status(), [403, 404], true)) {
                continue;
            }

            $leaked[] = $name.' ('.$url.') answered '.$response->status().' to an administrator of another company';
        }

        sort($leaked);

        $this->assertSame([], $leaked, count($leaked).' page(s) served another company’s record to a '
            ."full administrator of a different tenant (§75, §84):\n  ".implode("\n  ", $leaked));
    }

    /**
     * The same question of the verbs that change things.
     *
     * Nothing in this codebase had asked it. `Phase1SmokeTest` enumerates GET only, so all 580
     * authenticated write routes were reached by no smoke pass at all — and a page that renders
     * correctly for the right person while its POST deletes the record for anybody is one module
     * where only half the question was being put.
     *
     * The bodies are deliberately empty. A write that gets far enough to complain about a missing
     * field has already decided the caller may act on the record, and that decision is the whole
     * subject of this test — so a 422 or a redirect-back-with-errors is a FAILURE here, not a pass.
     * Route model binding runs before the controller, and the tenant scope fails closed, so the
     * honest answer to every one of these is 404 before anything is read or validated.
     */
    #[Test]
    public function an_administrator_of_another_company_is_refused_every_record_bound_write(): void
    {
        $leaked = [];

        foreach ($this->boundWrites() as $name => [$verb, $url]) {
            $response = $this->actingFresh($this->outsider)->{strtolower($verb)}($url);

            if (in_array($response->status(), [403, 404, 405], true)) {
                continue;
            }

            $leaked[] = $verb.' '.$name.' ('.$url.') answered '.$response->status()
                .' to an administrator of another company';
        }

        sort($leaked);

        $this->assertSame([], $leaked, count($leaked).' write route(s) accepted another company’s record '
            ."from a full administrator of a different tenant:\n  ".implode("\n  ", $leaked));
    }

    /**
     * Nothing the outsider did left a mark.
     *
     * A 403 answered after the write has already happened is a passing assertion about a breach.
     * Cheap to check and worth checking separately, because the status code alone cannot tell the
     * difference between "refused" and "done, then refused".
     */
    #[Test]
    public function the_refused_writes_changed_nothing(): void
    {
        $before = $this->withinOrganization($this->meridian, fn () => [
            'projects' => Project::query()->count(),
            'tasks' => Task::query()->count(),
            'documents' => Document::query()->count(),
            'employees' => Employee::query()->count(),
        ]);

        foreach ($this->boundWrites() as [$verb, $url]) {
            $this->actingFresh($this->outsider)->{strtolower($verb)}($url);
        }

        $after = $this->withinOrganization($this->meridian, fn () => [
            'projects' => Project::query()->count(),
            'tasks' => Task::query()->count(),
            'documents' => Document::query()->count(),
            'employees' => Employee::query()->count(),
        ]);

        $this->assertSame($before, $after, 'an administrator of another company changed Meridian’s row counts.');
    }

    #[Test]
    public function the_fixture_still_binds_enough_routes_for_this_test_to_mean_anything(): void
    {
        $bound = count($this->boundPages()) + count($this->boundWrites());

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_BOUND_ROUTES,
            $bound,
            'this test now binds only '.$bound.' record-bound routes, below its floor of '
            .self::MINIMUM_BOUND_ROUTES.'. A cross-tenant test that binds nothing passes perfectly, '
            .'so coverage shrinking is a failure rather than a quieter success.',
        );
    }

    // ================================================================== the fixture

    /**
     * One company's worth of records, keyed by the route parameter that names them.
     *
     * Built through the services rather than with `Model::create()`, for the reason the fixture
     * traits give at length: a record raised past its own service has no number, no opening status
     * and none of its register's defaults, so a page bound to it would render a state the
     * application cannot actually reach.
     *
     * @return array<string,mixed>
     */
    private function buildMeridianRecords(
        OrgUnit $department,
        User $colleague,
        User $storekeeper,
    ): array {
        $org = $this->meridian;
        $actor = $this->insider;

        $project = $this->makeProject($org, $actor, 'Marina Bridge Rehabilitation', [
            'org_unit_id' => $department->getKey(),
            'manager_id' => $actor->getKey(),
        ]);

        $task = $this->makeTask($org, $actor, 'Survey the eastern abutment', [
            'project_id' => $project->getKey(),
            'org_unit_id' => $department->getKey(),
            'assignee_id' => $colleague->getKey(),
            'due_on' => now()->addWeek()->toDateString(),
        ]);

        $category = $this->makeDocumentCategory($org, 'Procedures');
        $document = $this->makeDocument($org, $actor, 'Lifting operations procedure', [
            'document_category_id' => $category->getKey(),
            'project_id' => $project->getKey(),
        ]);
        $version = $this->makeNativeVersion($org, $document, $actor);

        $vendor = $this->makeApprovedVendor($org, $actor, 'Delta Fabrication');
        $client = $this->makeClient($org, $actor, 'Lagos Port Authority');
        // The party arrives as the two form fields, not as a model — that is the shape the service
        // has to defend against, so the fixture goes in the same way a controller would.
        $contract = $this->makeContract($org, $actor, 'Marina Bridge main works', [
            'party_type' => 'client',
            'party_id' => $client->getKey(),
        ]);

        $asset = $this->makeAsset($org, $actor, 'GEN-01');
        $vehicle = $this->makeVehicle($org, $asset, $actor);

        $warehouse = $this->makeWarehouse($org, $actor);
        $stockItem = $this->makeStockItem($org, $actor);

        // The procurement chain is built in full rather than shortcut, because each link refuses to
        // exist without the one before it: an enquiry needs an approved requisition, an order needs
        // a WON quotation, and a receipt needs an issued order. Duties are separated throughout —
        // the requester never approves — for the same reason the fixture traits give.
        $purchaseRequest = $this->makeApprovedPurchaseRequest($org, $colleague, $actor);
        $rfq = $this->makeRfq($org, $actor, $purchaseRequest, [$vendor]);
        $this->withinOrganization($org, fn () => app(RfqService::class)->issue($rfq, $actor));

        $quotation = $this->makeQuotation($org, $actor, $rfq, $vendor);
        $this->withinOrganization($org, fn () => app(RfqService::class)
            ->award($rfq, $quotation, $actor, 'Only compliant offer.'));

        $purchaseOrder = $this->makePurchaseOrder($org, $colleague, $quotation);
        $this->withinOrganization($org, function () use ($purchaseOrder, $colleague, $actor) {
            $service = app(PurchaseOrderService::class);
            $service->submit($purchaseOrder, $colleague);
            $service->approve($purchaseOrder, $actor);
            $service->issue($purchaseOrder, $actor);
        });

        $goodsReceipt = $this->makeGoodsReceipt($org, $storekeeper, $purchaseOrder->refresh());

        $employee = $this->withinOrganization($org, fn () => Employee::query()
            ->where('user_id', $colleague->getKey())->firstOrFail());

        $records = $this->withinOrganization($org, fn () => [
            'org_unit' => $department,

            // `{department}` binds a ProjectDepartment — the assignment row — not the org unit it
            // points at. Handing it the OrgUnit gave a 404 that looked exactly like a tenant
            // boundary holding, which is the failure this whole file is about. The lead is an
            // EMPLOYEE, not a user: assignment is an organizational fact, not an account.
            'department' => app(ProjectTeamService::class)->attachDepartment($project, [
                'org_unit_id' => $department->getKey(),
                'lead_employee_id' => $employee->getKey(),
            ], $actor),

            'employee' => $employee,
            'user' => $colleague,
            'role' => Role::query()->firstOrFail(),
            'position' => Position::create([
                'organization_id' => $org->getKey(),
                'org_unit_id' => $department->getKey(),
                'title' => 'Senior Structural Engineer',
                'is_active' => true,
            ]),
            'location' => Location::create([
                'organization_id' => $org->getKey(),
                'name' => 'Lagos Design Office',
                'is_active' => true,
            ]),
            'audit_log' => AuditLog::query()->latest('id')->firstOrFail(),

            // `{entityType}` is DELIBERATELY ABSENT, and it is the one exclusion worth writing down.
            // It binds the slug `task`, not a record — so `/configuration/statuses/task` carries no
            // company's id and Sahel's administrator reaching it with a 200 is correct: they are
            // configuring THEIR OWN task statuses. Binding it made seven routes report as leaks that
            // were nothing of the kind. The cross-tenant question only exists where an id is in the
            // URL, and a slug is not an id.
        ]);

        return [
            ...$records,
            'project' => $project,
            'task' => $task,
            'document' => $document,
            'version' => $version,
            'document_category' => $category,
            'vendor' => $vendor,
            'client' => $client,
            'contract' => $contract,
            'asset' => $asset,
            'vehicle' => $vehicle,
            'warehouse' => $warehouse,
            'stock_item' => $stockItem,
            'purchase_request' => $purchaseRequest,
            'rfq' => $rfq,
            'quotation' => $quotation,
            'purchase_order' => $purchaseOrder,
            'goods_receipt' => $goodsReceipt,
            'deliverable' => $this->makeDeliverable($org, $actor, 'Structural calculations', [
                'project_id' => $project->getKey(),
            ]),
            // With a controlled file attached, because `drawings.file` refuses a drawing that has
            // none — and a route that 403s for its own company proves nothing when the outsider
            // gets the same 403. The link goes on through `attachDocument`, not as a create
            // attribute: `document_id` is protected on create precisely so a form cannot point a
            // drawing at a file by posting an id.
            'drawing' => $this->withinOrganization($org, fn () => app(DrawingService::class)
                ->attachDocument(
                    $this->makeDrawing($org, $actor, 'Pier elevation', [
                        'project_id' => $project->getKey(),
                    ]),
                    $document,
                    $actor,
                )),
            'rfi' => $this->makeRfi($org, $actor, 'Confirm bearing plate grade', [
                'project_id' => $project->getKey(),
            ]),
            'submittal' => $this->makeSubmittal($org, $actor, 'Bearing plate data sheet', [
                'project_id' => $project->getKey(),
            ]),
            'change_request' => $this->makeChangeRequest($org, $actor, 'Additional pier survey', [
                'project_id' => $project->getKey(),
            ]),
            'material_request' => $this->makeMaterialRequest($org, $actor, $stockItem, $warehouse),
            'memo' => $this->makeMemo($org, $actor, 'Site access arrangements'),
            'announcement' => $this->makeAnnouncement($org, $actor, 'Office closure'),
            'meeting' => $this->makeMeeting($org, $actor, 'Weekly progress review'),
        ];
    }

    // ================================================================== route enumeration

    /**
     * Every named authenticated GET route this fixture can bind, as name => URL.
     *
     * ONLY THE PARAMETERISED ONES. A route with no parameters carries no company's id, so
     * requesting it as the outsider asks a different question — `/projects` correctly returns 200
     * with Sahel's own (empty) register on it, and calling that a leak would be wrong. The
     * cross-tenant question only exists where an id is in the URL.
     *
     * @return array<string,string>
     */
    private function boundPages(): array
    {
        $pages = [];

        foreach ($this->bindableRoutes() as $name => [$route, $parameters]) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $pages[$name] = route($name, $parameters);
        }

        ksort($pages);

        return $pages;
    }

    /**
     * The same, for POST/PUT/PATCH/DELETE, as name => [verb, URL].
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function boundWrites(): array
    {
        $writes = [];

        foreach ($this->bindableRoutes() as $name => [$route, $parameters]) {
            $verbs = array_values(array_diff($route->methods(), ['HEAD', 'GET']));

            if ($verbs === []) {
                continue;
            }

            $writes[$name] = [$verbs[0], route($name, $parameters)];
        }

        ksort($writes);

        return $writes;
    }

    /**
     * @return array<string,array{0:Route,1:array<string,mixed>}>
     */
    private function bindableRoutes(): array
    {
        $bindable = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            $names = $route->parameterNames();

            if ($names === []) {
                continue;   // carries no id, so it cannot be a cross-tenant question
            }

            $parameters = [];

            foreach ($names as $parameter) {
                if (! array_key_exists($parameter, $this->records)) {
                    continue 2;   // a subject this fixture does not build yet
                }

                $parameters[$parameter] = $this->records[$parameter];
            }

            $bindable[$name] = [$route, $parameters];
        }

        return $bindable;
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
