<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsEngineering;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Who can reach an engineering record — and, far more importantly, who cannot (§75, §84, §94).
 *
 * The five engineering registers are the first modules in the application that a person outside the
 * company routinely has an interest in. A drawing register tells a competitor what is being built; a
 * change register tells them what it is costing; an RFI answer is frequently the most commercially
 * sensitive sentence anybody writes all week. So this file does not test the happy path of the
 * registers at all — the lifecycle files do that. It tests the boundary, and it tests it the way an
 * attacker would:
 *
 *   · BY TYPING A DIFFERENT NUMBER IN THE URL. Every register is walked with a record id belonging
 *     to another tenant. The expected answer is 404 and not 403, because 403 confirms the record
 *     exists — which is itself a leak when the id is a sequence and the register is a project.
 *
 *   · BY ASKING FOR A LIST. Reach is compiled into the index query rather than filtered afterwards,
 *     so an index is checked for what it does NOT contain, not merely for what it does.
 *
 *   · BY WRITING RATHER THAN READING. A separate pass over the action routes, because a system that
 *     hides a foreign record on the page and then accepts a POST against its id has protected
 *     nothing at all (§6).
 *
 *   · BY GOING ROUND THE SIDE. The submittal document picker is a list of documents reached through
 *     a submittal permission. If it compiled through `submittal.edit` it would be a way to
 *     enumerate every document in the company from behind a register permission — so the test that
 *     it obeys `document.view` is here rather than with the submittals, because it is a leak test
 *     and not a feature test (§61).
 *
 * Nothing here relies on a view hiding a link. Every assertion is against an HTTP response.
 */
final class EngineeringAccessTest extends TestCase
{
    use BuildsEngineering, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private OrgUnit $civil;

    private User $dele;     // Meridian: sees everything on all five registers

    private User $amaka;    // Kestrel: the same authority, in a different company

    private User $sade;     // Meridian: `own` scope only — the in-tenant IDOR subject

    private ?DocumentCategory $documentCategory = null;

    /** @var array<string,array<string,array{0:string,1:Model,2:string}>> */
    private array $built = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->kestrel = $this->makeOrganization('Kestrel Infrastructure');

        $engineering = $this->makeUnit($this->meridian, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->meridian, 'Civil', OrgUnitType::Department, $engineering);

        $this->dele = $this->makePerson(
            $this->meridian,
            'Dele Okonkwo',
            $this->civil,
            [$this->makeRole($this->meridian, 'Engineering Manager', $this->grants('organization'))],
        );

        // Same job title, same grants, different company. The whole point of the pair.
        $kestrelUnit = $this->makeUnit($this->kestrel, 'Projects', OrgUnitType::Division);
        $this->amaka = $this->makePerson(
            $this->kestrel,
            'Amaka Nwosu',
            $kestrelUnit,
            [$this->makeRole($this->kestrel, 'Engineering Manager', $this->grants('organization'))],
        );

        // Everything at `own`: may raise records and work on the ones they raised, and has no reach
        // whatsoever over anybody else's. This is the ordinary shape of a junior engineer's access,
        // and it is the account the in-tenant IDOR tests are run as.
        $this->sade = $this->makePerson(
            $this->meridian,
            'Sade Adeyemi',
            $this->civil,
            [$this->makeRole($this->meridian, 'Graduate Engineer', $this->grants('own'))],
        );
    }

    // ================================================================== across tenants

    #[Test]
    public function a_record_belonging_to_another_company_is_not_found(): void
    {
        foreach ($this->kestrelRegisters() as $register => [$route, $record]) {
            $response = $this->actingFresh($this->dele)->get(route($route.'.show', $record));

            // Asserted on the code rather than through `assertNotFound()` so that a failure names
            // the register that leaked instead of reporting "expected 404" five times over.
            $this->assertSame(404, $response->status(), "The $register register let a foreign record through.");
        }
    }

    #[Test]
    public function an_index_never_lists_another_companys_records(): void
    {
        $mine = $this->meridianRegisters();
        $theirs = $this->kestrelRegisters();

        // Matched on the TITLE rather than the number, and that is not a convenience. Numbering is
        // per tenant, so both companies' first drawing is legitimately DWG-00001 — asserting on the
        // number would have this test pass or fail on a coincidence rather than on reach.
        // `view=all` on purpose. Every register opens on a working view — outstanding deliverables,
        // queries we owe an answer to, undecided changes — and a leak test that accepted those
        // defaults would be proving the default filter works, not that the tenant boundary does.
        foreach ($mine as $register => [$route, , $needle]) {
            $response = $this->actingFresh($this->dele)->get(route($route.'.index', ['view' => 'all']));

            $response->assertOk();
            $response->assertSee($needle);
            $response->assertDontSee($theirs[$register][2]);
        }
    }

    #[Test]
    public function a_write_against_another_companys_record_is_not_found_either(): void
    {
        [, $rfi] = $this->kestrelRegisters()['rfi'];
        [, $submittal] = $this->kestrelRegisters()['submittal'];
        [, $change] = $this->kestrelRegisters()['change_request'];

        // Reading is only half of it. A register that hides the row and then honours a POST against
        // its id has protected the page and nothing else.
        $this->actingFresh($this->dele)
            ->post(route('rfis.send', $rfi))->assertNotFound();

        $this->actingFresh($this->dele)
            ->post(route('submittals.submit', $submittal))->assertNotFound();

        $this->actingFresh($this->dele)
            ->post(route('change-requests.submit', $change))->assertNotFound();

        $this->actingFresh($this->dele)
            ->delete(route('rfis.destroy', $rfi))->assertNotFound();
    }

    #[Test]
    public function each_company_sees_its_own_register_and_believes_it_is_the_whole_world(): void
    {
        [, $meridian, $meridianTitle] = $this->meridianRegisters()['drawing'];
        [, , $kestrelTitle] = $this->kestrelRegisters()['drawing'];

        $this->actingFresh($this->amaka)
            ->get(route('drawings.index'))
            ->assertOk()
            ->assertSee($kestrelTitle)
            ->assertDontSee($meridianTitle);

        $this->actingFresh($this->amaka)
            ->get(route('drawings.show', $meridian))
            ->assertNotFound();
    }

    // ================================================================== inside one tenant

    #[Test]
    public function changing_the_id_in_the_url_does_not_reach_a_record_out_of_reach(): void
    {
        // Sade holds `view` on every engineering register — at `own`. The records below were raised
        // by somebody else, so the permission check passes and the REACH check is the only thing
        // standing between her and them. 403 rather than 404 is right here: the record is inside
        // her company, and pretending it does not exist would only teach people the register is
        // unreliable (§84, §94).
        foreach ($this->meridianRegisters() as $register => [$route, $record]) {
            $response = $this->actingFresh($this->sade)->get(route($route.'.show', $record));

            $this->assertSame(403, $response->status(), "The $register register let an out-of-reach record through.");
        }
    }

    #[Test]
    public function an_index_is_narrowed_by_reach_and_not_merely_by_tenant(): void
    {
        $theirs = $this->meridianRegisters()['deliverable'][1];
        $hers = $this->makeDeliverable($this->meridian, $this->sade, 'Foundation setting-out notes', [
            'project_id' => $theirs->getAttribute('project_id'),
        ]);

        $this->actingFresh($this->sade)
            ->get(route('deliverables.index', ['view' => 'all']))
            ->assertOk()
            ->assertSee($hers->number)
            ->assertDontSee($theirs->getAttribute('number'));
    }

    #[Test]
    public function a_register_is_refused_outright_to_somebody_who_holds_nothing_on_it(): void
    {
        $outsider = $this->makePerson(
            $this->meridian,
            'Bola Ige',
            $this->civil,
            [$this->makeRole($this->meridian, 'Storekeeper', ['task.view' => 'own'])],
        );

        foreach ($this->meridianRegisters() as $register => [$route]) {
            $response = $this->actingFresh($outsider)->get(route($route.'.index'));

            $this->assertSame(403, $response->status(), "The $register index opened for somebody holding no permission on it.");
        }
    }

    // ================================================================== the side door

    #[Test]
    public function the_submittal_document_picker_obeys_document_view_and_not_the_submittal_permission(): void
    {
        // A document Sade cannot open: raised by Dele, and her `document.view` is `own`.
        $confidential = $this->makeDocument($this->meridian, $this->dele, 'Client cost model assumptions', [
            'document_category_id' => $this->register()->id,
        ]);
        $this->makeNativeVersion($this->meridian, $confidential, $this->dele);

        $hers = $this->makeDocument($this->meridian, $this->sade, 'Rebar bending schedule', [
            'document_category_id' => $this->register()->id,
        ]);
        $this->makeNativeVersion($this->meridian, $hers, $this->sade);

        $package = $this->makeSubmittal($this->meridian, $this->sade, 'Reinforcement package');

        // She may edit her own submittal, so the picker opens. What it may show her is decided by
        // `document.view`, which is the only reason this endpoint is not a document-enumeration
        // hole wearing a submittal permission (§61).
        $this->actingFresh($this->sade)
            ->get(route('submittals.picker', $package))
            ->assertOk()
            ->assertSee($hers->title)
            ->assertDontSee($confidential->title);
    }

    #[Test]
    public function a_revision_that_cannot_be_opened_cannot_be_added_to_a_package_by_id_either(): void
    {
        $confidential = $this->makeDocument($this->meridian, $this->dele, 'Client cost model assumptions', [
            'document_category_id' => $this->register()->id,
        ]);
        $version = $this->makeNativeVersion($this->meridian, $confidential, $this->dele);

        $package = $this->makeSubmittal($this->meridian, $this->sade, 'Reinforcement package');

        // Hiding it from the picker is presentation. This is the enforcement: the id is posted
        // directly, and the controller authorizes the VERSION rather than trusting that it could
        // only have come from a list it had already filtered (§84).
        $this->actingFresh($this->sade)
            ->post(route('submittals.items.store', $package), [
                'document_version_id' => $version->id,
                'description' => 'Anything at all',
            ])
            ->assertForbidden();

        $this->assertSame(0, $package->items()->count());
    }

    // ================================================================== fixtures

    /**
     * The same grants at whatever scope the caller wants, across all five registers.
     *
     * @return array<string,string>
     */
    private function grants(string $scope): array
    {
        $grants = [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'document.view' => $scope,
            'document.create' => 'organization',
            'document.edit' => $scope,
        ];

        foreach (['deliverable', 'drawing', 'rfi', 'submittal', 'change_request'] as $module) {
            $grants[$module.'.view'] = $scope;
            $grants[$module.'.create'] = 'organization';
            $grants[$module.'.edit'] = $scope;
            $grants[$module.'.delete'] = $scope;
            $grants[$module.'.submit'] = $scope;
        }

        return $grants;
    }

    /**
     * One record on each register, raised by that company's manager.
     *
     * @return array<string,array{0:string,1:Model,2:string}>
     */
    private function meridianRegisters(): array
    {
        return $this->registers($this->meridian, $this->dele, 'Meridian');
    }

    /** @return array<string,array{0:string,1:Model,2:string}> */
    private function kestrelRegisters(): array
    {
        return $this->registers($this->kestrel, $this->amaka, 'Kestrel');
    }

    /**
     * Memoized per test, because half these tests ask for both companies' fixtures more than once
     * and a second call that quietly created a second set would make an `assertDontSee` pass by
     * accident. Held on the instance rather than in a static, because PHPUnit builds a new instance
     * per test while the database is rolled back between them — a static would survive the rollback
     * and hand the next test models whose rows no longer exist.
     *
     * @return array<string,array{0:string,1:Model,2:string}>
     */
    private function registers(Organization $organization, User $actor, string $key): array
    {
        if (isset($this->built[$key])) {
            return $this->built[$key];
        }

        // Deliverables are the one register whose project is NOT NULL, which is correct — a
        // deliverable is a thing somebody owes on a job, and one that belongs to no job is a to-do
        // list entry. So the fixture opens a project first and puts all five on it, which also
        // gives the reach tests a second axis to fail on if project scoping ever regresses.
        $project = $this->makeProject($organization, $actor, $key.' Terminal Expansion');
        $on = ['project_id' => $project->id];

        $titles = [
            'deliverable' => $key.' foundation design',
            'drawing' => $key.' pile cap detail',
            'rfi' => $key.' cover to reinforcement',
            'submittal' => $key.' rebar certificates',
            'change_request' => $key.' extra piling',
        ];

        return $this->built[$key] = [
            'deliverable' => ['deliverables', $this->makeDeliverable($organization, $actor, $titles['deliverable'], $on), $titles['deliverable']],
            'drawing' => ['drawings', $this->makeDrawing($organization, $actor, $titles['drawing'], $on), $titles['drawing']],
            'rfi' => ['rfis', $this->makeRfi($organization, $actor, $titles['rfi'], $on), $titles['rfi']],
            'submittal' => ['submittals', $this->makeSubmittal($organization, $actor, $titles['submittal'], $on), $titles['submittal']],
            'change_request' => ['change-requests', $this->makeChangeRequest($organization, $actor, $titles['change_request'], $on), $titles['change_request']],
        ];
    }

    /** A document register that does not force everything through an approval route. */
    private function register(): DocumentCategory
    {
        return $this->documentCategory ??= $this->makeDocumentCategory($this->meridian, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
        ]);
    }
}
