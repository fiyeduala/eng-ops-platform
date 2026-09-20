<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\ReportDefinition;
use App\Models\SavedFilter;
use App\Models\User;
use App\Services\ReportDefinitionService;
use App\Services\SavedFilterService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Every door into the reporting module, tried by the wrong person (§73, §74, §75, §94, Part 6F).
 *
 * THE OTHER TESTS IN THIS DIRECTORY CHECK BEHAVIOUR; THIS ONE CHECKS COVERAGE. A module is not made
 * safe by the routes somebody remembered to write a test for — it is made safe by every route being
 * tried, which means the list of routes has to be written down somewhere a reviewer can compare it
 * against `routes/web.php`. That list is `routes()` at the bottom of this file, and the two sweeps
 * above it run the whole thing twice:
 *
 *   ANOTHER COMPANY'S RECORDS DO NOT EXIST (§75). Not "are refused" — do not exist. A 403 confirms
 *   there is something there to be refused, which for a report named "contractors under
 *   investigation" is most of the disclosure. Every route gets another tenant's ids while the actor
 *   holds every reporting permission the catalogue defines, because a permission is not a passport.
 *
 *   A PERSON WITHOUT THE PERMISSION IS REFUSED SERVER-SIDE (§6). The sidebar hides these screens and
 *   that is worth nothing: the sweep posts, puts and deletes directly, with a session, at real ids
 *   in the actor's own company.
 *
 * AND THE WALKTHROUGH AT THE END IS §73 — a non-developer configuring a report and a shared view
 * end to end, through the screens, with no seeder, no console command and no SQL.
 */
final class ReportAccessSweepTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private User $tunde;

    private ReportDefinition $report;

    private SavedFilter $view;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department);

        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', $this->civil, [
            $this->makeRole($this->org, 'Operations Director', [
                'document.view' => 'organization',
                'document.create' => 'organization',
                'report.view' => 'organization',
                'report.create' => 'organization',
                'report.edit' => 'organization',
                'report.delete' => 'organization',
                'report.export' => 'organization',
                'org_unit.view' => 'organization',
            ]),
        ]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);

        [$this->report, $this->view] = $this->buildFor($this->org, $this->tunde);
    }

    // ================================================================== §75 and §94

    #[Test]
    public function every_reporting_route_refuses_another_companys_ids_as_not_found(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');

        $bilkisu = $this->makePerson($other, 'Bilkisu Sani', $this->makeUnit($other, 'Fabrication', OrgUnitType::Department), [
            $this->makeRole($other, 'Admin', [
                'report.view' => 'organization',
                'report.create' => 'organization',
                'report.edit' => 'organization',
                'report.delete' => 'organization',
                'report.export' => 'organization',
            ]),
        ]);

        [$theirReport, $theirView] = $this->buildFor($other, $bilkisu);

        foreach ($this->routes($theirReport, $theirView) as $label => [$method, $url, $payload]) {
            if (! str_contains($label, '{')) {
                continue;   // index, create and store carry no id to borrow
            }

            $response = $this->actingFresh($this->tunde)->call($method, $url, $payload);

            $this->assertSame(404, $response->getStatusCode(),
                $label.' let a director reach another company — with any status other than 404 it '
                .'also confirmed the record exists.');
        }

        // And nothing in this company was touched on the way past.
        $this->assertSame(1, ReportDefinition::query()->withoutGlobalScopes()
            ->where('organization_id', $other->getKey())->count());
        $this->assertSame(1, SavedFilter::query()->withoutGlobalScopes()
            ->where('organization_id', $other->getKey())->count());
    }

    // ================================================================== §6

    #[Test]
    public function every_reporting_route_refuses_somebody_without_the_permission(): void
    {
        // A real employee of this company, with a real session, and no reporting permission at all.
        // The sidebar never offers him these screens; he types them.
        $chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['document.view' => 'own']),
        ]);

        foreach ($this->routes($this->report, $this->view) as $label => [$method, $url, $payload]) {
            $response = $this->actingFresh($chidi)->call($method, $url, $payload);

            $this->assertSame(403, $response->getStatusCode(), $label.' was open to a storeman.');
        }

        $this->assertSame('Procedures on file', $this->report->fresh()->name);
        $this->assertSame(1, SavedFilter::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function reading_a_report_is_not_permission_to_build_export_or_delete_one(): void
    {
        $reader = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [
            $this->makeRole($this->org, 'Document Controller', [
                'document.view' => 'department',
                'report.view' => 'organization',
            ]),
        ]);

        $this->actingFresh($reader)->get(route('configuration.reports.show', $this->report))->assertOk();

        // Four separate permissions, because they are four separate things a company decides about
        // a person. A file leaves the building; a screen does not.
        $this->actingFresh($reader)->get(route('configuration.reports.create'))->assertForbidden();
        $this->actingFresh($reader)->get(route('configuration.reports.edit', $this->report))->assertForbidden();
        $this->actingFresh($reader)->delete(route('configuration.reports.destroy', $this->report))->assertForbidden();
        $this->actingFresh($reader)
            ->get(route('configuration.reports.export', ['report' => $this->report, 'format' => 'csv']))
            ->assertForbidden();

        // But keeping his own shortlist of a report he may read is not a fifth permission.
        $this->actingFresh($reader)
            ->post(route('configuration.reports.views.store', $this->report), [
                'name' => 'Civil only',
                'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->getKey()]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    // ================================================================== §73

    #[Test]
    public function a_report_and_a_shared_view_can_be_set_up_entirely_through_the_screens(): void
    {
        $this->makeDocument($this->org, $this->tunde, 'Excavation procedure', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
        ]);

        // 1. Open the builder from the Configuration Center. No seeder, no console command, no SQL.
        $this->actingFresh($this->tunde)->get(route('configuration.reports.index'))->assertOk();
        $this->actingFresh($this->tunde)->get(route('configuration.reports.create'))->assertOk()
            ->assertSee('Columns', false);

        // 2. Build a report by ticking fields — the register, the columns and the shape.
        $this->actingFresh($this->tunde)->post(route('configuration.reports.store'), [
            'name' => 'Civil procedures',
            'description' => 'Everything Civil has on file.',
            'entity_type' => 'document',
            'columns' => ['title', 'org_unit_id'],
            'group_field' => 'org_unit_id',
            'sort_field' => 'created_at',
            'sort_direction' => 'desc',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $built = ReportDefinition::query()->withoutGlobalScopes()->where('name', 'Civil procedures')->sole();

        // 3. Run it and narrow it on screen.
        $narrowed = route('configuration.reports.show', $built).'?'.http_build_query([
            'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->getKey()]],
        ]);

        $this->actingFresh($this->tunde)->get($narrowed)->assertOk()->assertSee('Excavation procedure');

        // 4. Keep the narrowing under a name, and share it with the company.
        $this->actingFresh($this->tunde)->post(route('configuration.reports.views.store', $built), [
            'name' => 'Civil only',
            'visibility' => 'organization',
            'filters' => [['field' => 'org_unit_id', 'operator' => '=', 'value' => (string) $this->civil->getKey()]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shared = SavedFilter::query()->withoutGlobalScopes()->where('name', 'Civil only')->sole();

        // 5. A colleague hired afterwards finds the shared view waiting on the report, applies it,
        //    and gets their own rows — the point of the whole part, tested from the outside.
        $adaeze = $this->makePerson($this->org, 'Adaeze Okafor', $this->civil, [
            $this->makeRole($this->org, 'Engineer', [
                'document.view' => 'department',
                'report.view' => 'organization',
            ]),
        ]);

        $this->actingFresh($adaeze)->get(route('configuration.reports.show', $built))
            ->assertOk()
            ->assertSee('Civil only')
            ->assertSee('are allowed to see');

        $applied = $this->actingFresh($adaeze)->get(route('configuration.reports.views.apply', [
            'report' => $built, 'savedFilter' => $shared,
        ]))->assertRedirect();

        $this->actingFresh($adaeze)->get($applied->headers->get('Location'))
            ->assertOk()
            ->assertSee('Excavation procedure');
    }

    // ================================================================== fixture

    /**
     * Every route in the module, as a reviewer can check them off against `routes/web.php`.
     *
     * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
     */
    private function routes(ReportDefinition $report, SavedFilter $view): array
    {
        $payload = [
            'name' => 'Swept',
            'entity_type' => 'document',
            'columns' => ['title'],
        ];

        $condition = ['filters' => [['field' => 'org_unit_id', 'operator' => 'is_null', 'value' => null]]];

        return [
            'GET  reports' => ['GET', route('configuration.reports.index'), []],
            'GET  reports/new' => ['GET', route('configuration.reports.create'), []],
            'POST reports' => ['POST', route('configuration.reports.store'), $payload],
            'GET  reports/{report}' => ['GET', route('configuration.reports.show', $report), []],
            'GET  reports/{report}/edit' => ['GET', route('configuration.reports.edit', $report), []],
            'PUT  reports/{report}' => ['PUT', route('configuration.reports.update', $report), $payload],
            'PUT  reports/{report}/active' => ['PUT', route('configuration.reports.toggle', $report), []],
            'DEL  reports/{report}' => ['DELETE', route('configuration.reports.destroy', $report), []],
            'GET  reports/{report}/export/csv' => ['GET', route('configuration.reports.export',
                ['report' => $report, 'format' => 'csv']), []],
            'GET  reports/{report}/export/pdf' => ['GET', route('configuration.reports.export',
                ['report' => $report, 'format' => 'pdf']), []],
            'POST reports/{report}/views' => ['POST', route('configuration.reports.views.store', $report),
                ['name' => 'Swept'] + $condition],
            'GET  reports/{report}/views/{savedFilter}' => ['GET', route('configuration.reports.views.apply',
                ['report' => $report, 'savedFilter' => $view]), []],
            'PUT  reports/{report}/views/{savedFilter}' => ['PUT', route('configuration.reports.views.update',
                ['report' => $report, 'savedFilter' => $view]), ['name' => 'Swept']],
            'DEL  reports/{report}/views/{savedFilter}' => ['DELETE', route('configuration.reports.views.destroy',
                ['report' => $report, 'savedFilter' => $view]), []],
        ];
    }

    /**
     * A report and a shared view inside one company, built through the services.
     *
     * @return array{0:ReportDefinition,1:SavedFilter}
     */
    private function buildFor(Organization $organization, User $actor): array
    {
        return $this->withinOrganization($organization, function () use ($actor) {
            $report = app(ReportDefinitionService::class)->create([
                'name' => 'Procedures on file',
                'entity_type' => 'document',
                'columns' => ['title', 'org_unit_id'],
            ], $actor);

            $view = app(SavedFilterService::class)->create([
                'name' => 'Unfiled',
                'entity_type' => 'document',
                'filters' => [['field' => 'org_unit_id', 'operator' => 'is_null', 'value' => null]],
                'visibility' => 'organization',
            ], $actor);

            return [$report, $view];
        });
    }
}
