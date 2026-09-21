<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Engines\Access\ReachInspector;
use App\Engines\Kpi\SnapshotSweeper;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\KpiDefinition;
use App\Models\KpiSnapshot;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\KpiDefinitionService;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The first stored figures in the platform, and the rule that makes storing them safe.
 *
 * PHASE 7 CACHED NOTHING, ON PURPOSE. Every KPI was counted live through the visibility of whoever
 * asked, because a total belongs to whoever it was counted for and handing it to the next reader
 * hands them that person's reach. `KpiDefinitionTest` pins that down: three people, one definition,
 * three different and equally correct answers.
 *
 * PHASE 8 HAS TO STORE FIGURES ANYWAY. A command center with twenty tiles cannot run twenty scoped
 * aggregates per page load (§95), and a trend line drawn from live data rewrites its own history
 * every time somebody closes an old record (docs/11 §5).
 *
 * THE RECONCILIATION IS THE SUBJECT OF THIS FILE. A stored figure is stamped with the SCOPE it was
 * counted over — a company, a department subtree, a project — and never with a viewer. It is handed
 * to somebody only when their reach contains that whole scope; everybody else gets their own live
 * figure and a sentence saying so. These tests exist to prove that the second half is real, because
 * the first half on its own is just a faster way to leak a count.
 *
 * TWO OF THEM ARE THE ONES THAT WOULD HAVE BEEN MISSED. Holding a permission organization-wide is
 * NOT the same as seeing every row: confidentiality drops records from the count, and a single deny
 * row withholds one. Either makes a shared figure wrong for that person, in the direction that
 * matters — the gap between the shared number and their own list is exactly what somebody hid.
 *
 *   Engineering (division)   Emeka   sees the whole division
 *   Civil (department)       Ngozi   sees Civil only
 *   Piping (department)      Sade    sees Piping only
 *   (company)                Tunde   sees everything, including confidential
 *   (company)                Kemi    sees everything EXCEPT confidential
 */
final class KpiSnapshotTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $emeka;

    private User $ngozi;

    private User $sade;

    private User $tunde;

    private User $kemi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $divisionHead = $this->makeRole($this->org, 'Division Head', [
            'document.view' => 'division',
            'document.view_confidential' => 'division',
            'kpi.view' => 'organization',
        ]);

        $departmental = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'document.view_confidential' => 'department',
            'kpi.view' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.view_confidential' => 'organization',
            'document.create' => 'organization',
            'kpi.view' => 'organization',
            'kpi.manage' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);

        // Everything except clearance. This is the role that makes the confidentiality test a test
        // rather than a restatement of the permission check.
        $uncleared = $this->makeRole($this->org, 'Company Secretary', [
            'document.view' => 'organization',
            'kpi.view' => 'organization',
        ]);

        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->engineering, [$divisionHead]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$departmental]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$departmental]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->kemi = $this->makePerson($this->org, 'Kemi Alabi', null, [$uncleared]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== what is stored

    #[Test]
    public function a_stored_figure_is_counted_over_a_scope_and_belongs_to_nobody(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Excavation procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');

        $kpi = $this->kpi();

        $this->sweep();

        $point = $this->pointFor($kpi, 'organization', null);

        // Three, which is nobody's answer in particular. Ngozi would have counted two and Sade one
        // (see KpiDefinitionTest); this is the company's figure, which is precisely why it cannot
        // be handed out without asking who is looking.
        $this->assertSame('3.0000', $point->value);
        $this->assertSame('document', $point->entity_type);
    }

    #[Test]
    public function the_stored_figure_does_not_record_who_it_was_counted_for(): void
    {
        // There is no such person, and the absence of a column is what guarantees it. A viewer id
        // here would turn every row into one person's reach, stored and waiting to be read by the
        // next person — the exact failure Phase 7 refused to build and this table has to keep
        // refusing while storing numbers anyway.
        foreach (['user_id', 'viewer_id', 'counted_for', 'countedFor', 'for_user_id'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('kpi_snapshots', $forbidden),
                'kpi_snapshots must not record who a figure was counted for; found '.$forbidden
            );
        }
    }

    #[Test]
    public function a_second_sweep_on_the_same_day_moves_the_point_rather_than_drawing_it_twice(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $kpi = $this->kpi();

        $this->sweep();
        $this->documentIn($this->piping, 'Hydrotest procedure');
        $this->sweep();

        $points = $this->withinOrganization($this->org, fn () => KpiSnapshot::query()
            ->forDefinition($kpi)
            ->atScope('organization', null)
            ->get());

        // Idempotent by key rather than by a "last run" stamp, so re-running after a fix is always
        // safe and there is no bookkeeping row to fall out of step with reality.
        $this->assertCount(1, $points);
        $this->assertSame('2.0000', $points->first()->value);
    }

    #[Test]
    public function a_measure_that_can_no_longer_be_counted_is_recorded_as_unavailable_rather_than_skipped(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $kpi = $this->kpi();

        $this->sweep();

        // The register this measure names is withdrawn — an upgrade, a module switched off. A
        // definition outlives the register it names (§84), and what matters here is the next sweep.
        $this->withinOrganization($this->org, fn () => $kpi
            ->forceFill(['entity_type' => 'a_register_that_no_longer_exists'])
            ->saveQuietly());

        $this->sweep(Carbon::now()->addDay());

        $point = $this->pointFor($kpi, 'organization', null, Carbon::now()->addDay());

        // Recorded as a hole, not skipped. Skipping would leave yesterday's number standing under
        // today's label, and a flat line reads as stability while the thing measuring it is dead.
        $this->assertNull($point->value);
        $this->assertNotNull($point->unavailable_reason);
    }

    #[Test]
    public function a_measure_about_one_person_is_never_stored(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');

        $personal = $this->kpi(['name' => 'Documents I own', 'scope' => 'employee']);

        $this->sweep();

        // A figure about one named individual cannot be made safe by the read rule — there is no
        // "scope" a second person's reach could contain — and it is performance data besides. It
        // stays live-only, so there is nothing to file and nothing to leak.
        $this->assertSame(0, $this->withinOrganization($this->org,
            fn () => KpiSnapshot::query()->forDefinition($personal)->count()));
    }

    #[Test]
    public function one_companys_figures_are_never_swept_into_anothers(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $kpi = $this->kpi();

        $rival = $this->makeOrganization('Continental Fabricators');
        $rivalUnit = $this->makeUnit($rival, 'Works', OrgUnitType::Department);
        $rivalBoss = $this->makePerson($rival, 'Ada Nwosu', $rivalUnit, [
            $this->makeRole($rival, 'Director', [
                'document.view' => 'organization',
                'kpi.view' => 'organization',
                'kpi.manage' => 'organization',
            ]),
        ]);
        $rivalCategory = $this->makeDocumentCategory($rival, 'Procedures', ['requires_approval' => false]);
        $this->makeDocument($rival, $rivalBoss, 'Welding procedure', [
            'document_category_id' => $rivalCategory->getKey(),
            'org_unit_id' => $rivalUnit->getKey(),
        ]);

        $this->sweep();

        // Meridian's measure counted Meridian's one document. The sweep runs tenant by tenant
        // inside each organization's own context, so the ordinary global scope does the isolating
        // rather than being switched off for a bulk read (§75).
        $this->assertSame('1.0000', $this->pointFor($kpi, 'organization', null)->value);
    }

    // ================================================================== who may read one

    #[Test]
    public function somebody_who_sees_the_whole_company_may_read_the_company_figure(): void
    {
        $verdict = $this->reach()->coversOrganization($this->tunde, new Document, 'document.view');

        $this->assertTrue($verdict->covers);
        $this->assertNull($verdict->reason);
    }

    #[Test]
    public function somebody_who_sees_one_department_may_not_read_the_company_figure(): void
    {
        $verdict = $this->reach()->coversOrganization($this->ngozi, new Document, 'document.view');

        // Not an error and not a blank — a fallback. The tile still shows Ngozi a number; it shows
        // her own, and says so, because two people reading different numbers under one heading with
        // no explanation is how somebody decides the platform is broken.
        $this->assertFalse($verdict->covers);
        $this->assertNotNull($verdict->reason);
    }

    #[Test]
    public function a_department_head_may_read_their_own_departments_figure_and_not_a_sisters(): void
    {
        $inspector = $this->reach();

        $this->assertTrue(
            $inspector->coversOrgUnit($this->ngozi, new Document, 'document.view', $this->civil->getKey())->covers
        );

        // Piping is the same size, the same altitude and the same permission. Reach is about where
        // she stands, not about what rank she holds, which is why containment is tested as set
        // containment rather than by comparing scope names.
        $this->assertFalse(
            $inspector->coversOrgUnit($this->ngozi, new Document, 'document.view', $this->piping->getKey())->covers
        );
    }

    #[Test]
    public function a_division_head_reaches_every_department_inside_the_division(): void
    {
        $inspector = $this->reach();

        foreach ([$this->engineering, $this->civil, $this->piping] as $unit) {
            $this->assertTrue(
                $inspector->coversOrgUnit($this->emeka, new Document, 'document.view', $unit->getKey())->covers,
                'A division head should contain '.$unit->name
            );
        }
    }

    #[Test]
    public function a_department_head_does_not_reach_the_division_above_them(): void
    {
        // The organogram is walked downward for reach and never upward. Ngozi sitting inside
        // Engineering says where she reports, not what she can count.
        $this->assertFalse(
            $this->reach()->coversOrgUnit($this->ngozi, new Document, 'document.view', $this->engineering->getKey())->covers
        );
    }

    // ================================================================== the two that hide

    #[Test]
    public function seeing_every_record_except_the_confidential_ones_is_not_seeing_every_record(): void
    {
        $inspector = $this->reach();

        // Kemi holds `document.view` organization-wide. On a permission check alone she covers the
        // company, and handing her the company figure would tell her the count of exactly the
        // records she was specifically not shown — an oracle over the confidential set, one digit
        // at a time.
        $verdict = $inspector->coversOrganization($this->kemi, new Document, 'document.view');

        $this->assertFalse($verdict->covers);
        $this->assertStringContainsString('confidential', $verdict->reason);

        // Tunde holds the same permission and the clearance, so for him nothing is hidden.
        $this->assertTrue($inspector->coversOrganization($this->tunde, new Document, 'document.view')->covers);
    }

    #[Test]
    public function one_record_withheld_from_somebody_is_enough_to_stop_a_shared_figure(): void
    {
        $document = $this->documentIn($this->civil, 'Board restructuring paper');

        $this->assertTrue(
            $this->reach()->coversOrganization($this->tunde, new Document, 'document.view')->covers,
            'Tunde should cover the company before anything is withheld'
        );

        DB::table('record_permissions')->insert([
            'organization_id' => $this->org->getKey(),
            'record_type' => (new Document)->getMorphClass(),
            'record_id' => $document->getKey(),
            'grantee_type' => 'user',
            'grantee_id' => $this->tunde->getKey(),
            'ability' => 'document.view',
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $verdict = $this->reach()->coversOrganization($this->tunde, new Document, 'document.view');

        // Gate 4a denies one record to one person however broad their grant. The company figure
        // includes that record; Tunde's own list does not. The gap between the two IS the record
        // somebody deliberately hid, so he reads his own figure from here on.
        $this->assertFalse($verdict->covers);
        $this->assertStringContainsString('withheld', $verdict->reason);
    }

    #[Test]
    public function a_denial_on_a_different_ability_does_not_suppress_a_view_figure(): void
    {
        $document = $this->documentIn($this->civil, 'Lifting operations procedure');

        DB::table('record_permissions')->insert([
            'organization_id' => $this->org->getKey(),
            'record_type' => (new Document)->getMorphClass(),
            'record_id' => $document->getKey(),
            'grantee_type' => 'user',
            'grantee_id' => $this->tunde->getKey(),
            'ability' => 'document.approve',
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Being barred from approving one drawing does not change how many drawings there are. The
        // ability match is the same one ScopeCompiler::aclSubquery() makes, and without it every
        // approval restriction in the company would quietly switch off its shared figures.
        $this->assertTrue(
            $this->reach()->coversOrganization($this->tunde, new Document, 'document.view')->covers
        );
    }

    #[Test]
    public function an_expired_denial_stops_suppressing_the_figure(): void
    {
        $document = $this->documentIn($this->civil, 'Lifting operations procedure');

        DB::table('record_permissions')->insert([
            'organization_id' => $this->org->getKey(),
            'record_type' => (new Document)->getMorphClass(),
            'record_id' => $document->getKey(),
            'grantee_type' => 'user',
            'grantee_id' => $this->tunde->getKey(),
            'ability' => 'document.view',
            'effect' => 'deny',
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A lapsed restriction is not a restriction. The same unexpired-row rule the compiler
        // applies row by row is applied here once for the whole register.
        $this->assertTrue(
            $this->reach()->coversOrganization($this->tunde, new Document, 'document.view')->covers
        );
    }

    #[Test]
    public function another_companys_denial_is_not_this_persons_problem(): void
    {
        $document = $this->documentIn($this->civil, 'Lifting operations procedure');

        $rival = $this->makeOrganization('Continental Fabricators');

        DB::table('record_permissions')->insert([
            'organization_id' => $rival->getKey(),
            'record_type' => (new Document)->getMorphClass(),
            'record_id' => $document->getKey(),
            'grantee_type' => 'user',
            'grantee_id' => $this->tunde->getKey(),
            'ability' => 'document.view',
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A row filed under another tenant must not reach into this one, in either direction. It
        // cannot grant anything here and it must not be able to degrade anything here either (§75).
        $this->assertTrue(
            $this->reach()->coversOrganization($this->tunde, new Document, 'document.view')->covers
        );
    }

    // ================================================================== helpers

    private function reach(): ReachInspector
    {
        return $this->withinOrganization($this->org, fn () => app(ReachInspector::class));
    }

    private function sweep(?CarbonInterface $asOf = null): int
    {
        return $this->withinOrganization($this->org,
            fn () => app(SnapshotSweeper::class)->sweepCurrentTenant($asOf));
    }

    private function pointFor(
        KpiDefinition $kpi,
        string $scopeType,
        ?int $scopeId,
        ?CarbonInterface $asOf = null,
    ): KpiSnapshot {
        $point = $this->withinOrganization($this->org, fn () => KpiSnapshot::query()
            ->forDefinition($kpi)
            ->atScope($scopeType, $scopeId)
            ->where('period_key', $kpi->periodKeyFor($asOf ?? Carbon::now()))
            ->first());

        $this->assertNotNull($point, 'Expected a stored figure for '.$kpi->name.' at '.$scopeType);

        return $point;
    }

    /** @param  array<string,mixed>  $attributes */
    private function kpi(array $attributes = []): KpiDefinition
    {
        return $this->withinOrganization($this->org, fn () => app(KpiDefinitionService::class)->create([
            'name' => 'Procedures on file',
            'entity_type' => 'document',
            'aggregate' => 'count',
            'scope' => 'organization',
            'period' => 'all_time',
            ...$attributes,
        ], $this->tunde));
    }

    /** @param  array<string,mixed>  $attributes */
    private function documentIn(OrgUnit $unit, string $title, array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $unit->getKey(),
            ...$attributes,
        ]);
    }
}
