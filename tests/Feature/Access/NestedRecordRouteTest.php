<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\ProjectTeamService;
use App\Support\Enums\OrgUnitType;
use App\Support\PermissionCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsEngineering;
use Tests\Support\BuildsProcurement;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * §84 and §94, asked of the nested routes: the child is real, the tenant is right, and the parent in
 * the URL is somebody else's.
 *
 * `CrossTenantRouteTest` puts the boundary question — Org B reaching Org A. This is the attack that
 * survives a perfect answer to it. Everything here is ONE company's data, requested by a legitimate
 * administrator of that company, over routes they hold every permission for. The only thing wrong is
 * the first id in the URL:
 *
 *     /documents/{A}/versions/{a version of document B}
 *
 * Nothing in the tenant scope, the permission middleware or the policy has any reason to object. The
 * scope sees one company. The middleware sees a held permission. The policy is handed a document the
 * actor may genuinely read. The ONLY thing that can refuse this is the controller checking that the
 * child belongs to the parent it was reached through — and that check is one line, written by hand,
 * in each of 55 places.
 *
 * WHAT GOES WRONG WHEN IT IS MISSING is not usually "you can read something you shouldn't", because
 * in this test the actor can read both. It is that WRITES land on the wrong record. Post a revision
 * to `/documents/{the procedure}/versions/{a version of the contract}` and the contract's version
 * row is updated with the procedure's content, filed under the procedure's audit trail, in a
 * register that will report both as consistent. §64's gapless numbering and §59's protected audit
 * records both assume the row being written is the row named by the page.
 *
 * WHY THERE IS NO POSITIVE HTTP PASS. Most of these routes are PUT and DELETE, and a positive pass
 * over them would have to compose a valid body for each, then treat "it worked" and "it validated
 * but the record was wrong" as the same green. The thing a positive pass would have established —
 * that the fixture is not asking a question about records which do not exist — is asserted directly
 * instead, in `the_pairs_are_real`: the child genuinely belongs to its parent, the stranger genuinely
 * is a different row, and both are in the same company.
 *
 * THE ONE THING THAT WOULD HAVE MADE THIS VACUOUS, and did, for two routes: a FormRequest answers
 * before the controller. An empty PUT to a route with validation redirects with errors, which is a
 * refusal — but not the refusal under test, and it would stay green with the ownership check
 * deleted. Those routes are sent bodies that validate, so the only thing left to refuse them is the
 * check this file is about. See `payloadFor()`.
 */
final class NestedRecordRouteTest extends TestCase
{
    use BuildsCommercial, BuildsEngineering, BuildsProcurement, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    /**
     * The floor under this test's coverage, measured when written.
     *
     * Seven families out of the fifty-five nested routes in the application. That is not everything
     * and this constant is how the gap stays visible instead of being rounded up to "the nested
     * routes are tested". Adding a family below should raise it.
     */
    private const MINIMUM_CROSSED_ROUTES = 21;

    private Organization $org;

    private User $actor;

    private User $approver;

    /**
     * parent parameter => [child parameter => the child that belongs to `mine`]
     *
     * @var array<string,array<string,Model>>
     */
    private array $children = [];

    /**
     * parent parameter => the parent that child does NOT belong to.
     *
     * @var array<string,Model>
     */
    private array $strangers = [];

    /**
     * parent parameter => the parent that child DOES belong to.
     *
     * @var array<string,Model>
     */
    private array $owners = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->org = $this->makeOrganization('Meridian Engineering');

        $unit = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);

        $this->actor = $this->makePerson($this->org, 'Ada Okonkwo', $unit, [
            $this->makeRole($this->org, 'Administrator', $this->everything()),
        ]);

        $colleague = $this->makePerson($this->org, 'Tobias Renner', $unit);
        $this->approver = $approver = $this->makePerson($this->org, 'Ngozi Abara', $unit);

        $this->buildPairs($unit, $colleague, $approver);
    }

    // ================================================================== the fixture is sound

    /**
     * Before any refusal means anything: the child really is the owner's, and the stranger really is
     * a different record.
     *
     * A nested IDOR test where the two parents are the same row, or where the child belongs to
     * neither, passes every assertion below while testing nothing at all.
     */
    #[Test]
    public function the_pairs_are_real(): void
    {
        $this->assertNotSame([], $this->children, 'the fixture built no parent/child pairs.');

        foreach ($this->children as $parentName => $pairs) {
            $owner = $this->owners[$parentName];
            $stranger = $this->strangers[$parentName];

            $this->assertNotEquals(
                $owner->getKey(),
                $stranger->getKey(),
                $parentName.": the owner and the stranger are the same record, so every 'wrong parent' "
                .'URL below is in fact the right parent.',
            );

            $this->assertSame(
                (int) $owner->organization_id,
                (int) $stranger->organization_id,
                $parentName.': the stranger is in another company, which makes this a tenant test '
                .'rather than an ownership one — that question is CrossTenantRouteTest’s.',
            );

            foreach ($pairs as $childName => $child) {
                $this->assertSame(
                    (int) $owner->getKey(),
                    (int) $child->getAttribute($this->foreignKeyFor($parentName)),
                    $parentName.'/'.$childName.': the child does not belong to the owner, so the '
                    .'refusals below may be refusing a child that belongs to nobody.',
                );
            }
        }
    }

    // ================================================================== the question

    #[Test]
    public function a_child_cannot_be_reached_through_a_parent_it_does_not_belong_to(): void
    {
        $leaked = [];

        foreach ($this->crossedRoutes() as $name => [$verb, $url]) {
            $response = $this->send($name, $verb, $url);

            if (in_array($response->status(), [403, 404], true)) {
                continue;
            }

            $leaked[] = $verb.' '.$name.' ('.$url.') answered '.$response->status()
                .' for a child that belongs to a different parent';
        }

        sort($leaked);

        $this->assertSame([], $leaked, count($leaked).' nested route(s) accepted a child reached '
            ."through the wrong parent (§84, §94):\n  ".implode("\n  ", $leaked));
    }

    /**
     * And the mismatched writes left nothing behind.
     *
     * A route that answers 404 after updating the row is the failure this test exists to catch, and
     * the status code on its own cannot tell the two apart.
     */
    #[Test]
    public function the_refused_writes_changed_nothing(): void
    {
        $before = $this->childFingerprint();

        foreach ($this->crossedRoutes() as $name => [$verb, $url]) {
            $this->send($name, $verb, $url);
        }

        $this->assertSame($before, $this->childFingerprint(),
            'a child record was changed or deleted by a request that named the wrong parent.');
    }

    #[Test]
    public function the_fixture_still_crosses_enough_routes_for_this_test_to_mean_anything(): void
    {
        $crossed = count($this->crossedRoutes());

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_CROSSED_ROUTES,
            $crossed,
            'this test now crosses only '.$crossed.' nested routes, below its floor of '
            .self::MINIMUM_CROSSED_ROUTES.'.',
        );
    }

    // ================================================================== fixture

    private function buildPairs(OrgUnit $unit, User $colleague, User $approver): void
    {
        $org = $this->org;
        $actor = $this->actor;

        // --- documents and their versions
        $category = $this->makeDocumentCategory($org, 'Procedures');
        $mine = $this->makeDocument($org, $actor, 'Lifting operations procedure', [
            'document_category_id' => $category->getKey(),
        ]);
        $theirs = $this->makeDocument($org, $actor, 'Confined space procedure', [
            'document_category_id' => $category->getKey(),
        ]);
        $this->register('document', $mine, $theirs, [
            'version' => $this->makeNativeVersion($org, $mine, $actor),
        ]);

        // --- projects, their departments and their team
        $project = $this->makeProject($org, $actor, 'Marina Bridge Rehabilitation', [
            'org_unit_id' => $unit->getKey(),
            'manager_id' => $actor->getKey(),
        ]);
        $otherProject = $this->makeProject($org, $actor, 'Ogun River Crossing', [
            'org_unit_id' => $unit->getKey(),
            'manager_id' => $actor->getKey(),
        ]);

        $employee = $this->withinOrganization($org, fn () => Employee::query()
            ->where('user_id', $colleague->getKey())->firstOrFail());

        [$department, $member] = $this->withinOrganization($org, function () use ($project, $unit, $employee, $colleague, $actor) {
            $team = app(ProjectTeamService::class);

            return [
                $team->attachDepartment($project, [
                    'org_unit_id' => $unit->getKey(),
                    'lead_employee_id' => $employee->getKey(),
                ], $actor),
                $team->addMember($project, ['user_id' => $colleague->getKey()], $actor),
            ];
        });

        $this->register('project', $project, $otherProject, [
            'department' => $department,
            'member' => $member,
        ]);

        // --- submittals and their items
        $submittal = $this->makeSubmittalWithItem($org, $actor, 'Bearing plate data sheet', [
            'project_id' => $project->getKey(),
        ]);
        $otherSubmittal = $this->makeSubmittal($org, $actor, 'Grout mix design', [
            'project_id' => $project->getKey(),
        ]);
        $this->register('submittal', $submittal, $otherSubmittal, [
            'item' => $this->withinOrganization($org, fn () => $submittal->items()->firstOrFail()),
        ]);

        // --- enquiries and the suppliers invited to them
        $vendor = $this->makeApprovedVendor($org, $actor, 'Delta Fabrication');
        $request = $this->makeApprovedPurchaseRequest($org, $colleague, $approver);
        $rfq = $this->makeRfq($org, $actor, $request, [$vendor]);
        $otherRfq = $this->makeRfq($org, $actor, $request, [$vendor], ['title' => 'Second enquiry']);
        $this->register('rfq', $rfq, $otherRfq, [
            'invitation' => $this->withinOrganization($org, fn () => $rfq->invitations()->firstOrFail()),
        ]);

        // --- memos and their distribution lists
        $memo = $this->makeMemo($org, $actor, 'Site access arrangements', [], [$colleague]);
        $otherMemo = $this->makeMemo($org, $actor, 'Revised working hours');
        $this->register('memo', $memo, $otherMemo, [
            'recipient' => $this->withinOrganization($org, fn () => $memo->recipients()->firstOrFail()),
        ]);

        // --- workflows and their stages
        // Each stage names somebody: publishing refuses a stage whose audience resolves to nobody,
        // which is §78's engine declining to create an approval nobody can ever action.
        $workflow = $this->makeWorkflow($org, $actor, 'Document approval', 'document', [[
            'name' => 'Technical review',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $approver->getKey(),
        ]]);
        $otherWorkflow = $this->makeWorkflow($org, $actor, 'Memo approval', 'memo', [[
            'name' => 'Head of department',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $approver->getKey(),
        ]], ['is_default' => false]);
        $this->register('workflow', $workflow, $otherWorkflow, [
            'stage' => $this->withinOrganization($org, fn () => $workflow->stages()->firstOrFail()),
        ]);
    }

    /**
     * @param  array<string,Model>  $children
     */
    private function register(string $parameter, Model $owner, Model $stranger, array $children): void
    {
        $this->owners[$parameter] = $owner;
        $this->strangers[$parameter] = $stranger;
        $this->children[$parameter] = $children;
    }

    /** The column on the child that names its parent. */
    private function foreignKeyFor(string $parentParameter): string
    {
        return $parentParameter.'_id';
    }

    // ================================================================== route enumeration

    /**
     * Every two-parameter authenticated route this fixture can cross, as name => [verb, URL].
     *
     * The FIRST parameter is the parent — that is what the URI shape means, and `parameterNames()`
     * returns them in the order they appear. The URL is built with the stranger in that position and
     * the real child in the second, which is the whole attack in one line.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function crossedRoutes(): array
    {
        $crossed = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            $names = $route->parameterNames();

            if (count($names) !== 2) {
                continue;
            }

            [$parent, $child] = $names;

            if (! isset($this->strangers[$parent], $this->children[$parent][$child])) {
                continue;
            }

            $verbs = array_values(array_diff($route->methods(), ['HEAD']));

            $crossed[$name] = [$verbs[0], route($name, [
                $parent => $this->strangers[$parent],
                $child => $this->children[$parent][$child],
            ])];
        }

        ksort($crossed);

        return $crossed;
    }

    /**
     * Every child's identity and parent, as one comparable array.
     *
     * `updated_at` is in it deliberately: a write that changed a field back to the same value would
     * still move the timestamp, and "nothing happened" has to mean nothing happened.
     *
     * @return array<string,array<string,mixed>>
     */
    private function childFingerprint(): array
    {
        return $this->withinOrganization($this->org, function () {
            $fingerprint = [];

            foreach ($this->children as $parentName => $pairs) {
                foreach ($pairs as $childName => $child) {
                    $fresh = $child->newQuery()->find($child->getKey());

                    $fingerprint[$parentName.'/'.$childName] = $fresh === null
                        ? ['deleted' => true]
                        : [
                            'parent' => $fresh->getAttribute($this->foreignKeyFor($parentName)),
                            'updated_at' => (string) $fresh->getAttribute('updated_at'),
                        ];
                }
            }

            return $fingerprint;
        });
    }

    /**
     * One crossed request.
     *
     * THE GET GUARD IS NOT DECORATION. `$this->get($url, $array)` reads that array as HEADERS, not
     * as a body — so a payload added for a GET route would travel as headers, change nothing about
     * the request, and quietly turn that route's entry in `payloadFor()` into a lie while the test
     * stayed green. Refusing to send one makes that mistake a failure here.
     */
    private function send(string $name, string $verb, string $url): TestResponse
    {
        $payload = $this->payloadFor($name);

        if (strtoupper($verb) === 'GET') {
            $this->assertSame([], $payload,
                $name.' is a GET, so a body would be sent as headers and never reach the '
                .'controller. Remove it from payloadFor().');
        }

        return $this->actingFresh($this->actor)->{strtolower($verb)}($url, $payload);
    }

    /**
     * Bodies for the routes whose FormRequest would otherwise answer first.
     *
     * THIS IS THE DIFFERENCE BETWEEN A TEST AND A REASSURING NOISE. An empty PUT to
     * `workflows.stages.update` redirects with validation errors — a 302 that looks exactly like a
     * leak and is in fact a refusal, and which would have been recorded as a failure here while the
     * ownership check underneath it was never consulted at all. Worse, if that check were DELETED,
     * the empty request would still redirect and this test would still be green.
     *
     * So the two routes with a FormRequest in front of them are sent bodies that VALIDATE. The
     * request then reaches the controller, and the only thing left that can refuse it is
     * `assertBelongs`. If it ever stops refusing, the write lands on the wrong parent's child and
     * `the_refused_writes_changed_nothing` says so.
     *
     * A route added here needs a body good enough to pass; one that does not validate is a silently
     * weaker test, not a stricter one.
     *
     * @return array<string,mixed>
     */
    private function payloadFor(string $route): array
    {
        return match ($route) {
            'rfqs.invitations.decline' => [
                'comment' => 'No capacity in this quarter.',
            ],
            'workflows.stages.update' => [
                'name' => 'Technical review',
                'assignee_type' => 'user',
                'assignee_ref' => (string) $this->approver->getKey(),
                'action_type' => 'approve',
                'quorum' => 'all',
                'on_reject' => 'return_to_owner',
            ],
            default => [],
        };
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
