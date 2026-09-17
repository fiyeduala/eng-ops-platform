<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Engines\Access\AccessResolver;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class AssetAccessTest extends TestCase
{
    use BuildsAssets, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $unit;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Fleet access');
        $this->unit = $this->makeUnit($this->org, 'Fleet', OrgUnitType::Department);
        $this->manager = $this->viewer('Manager', $this->fleetGrants());
    }

    private function viewer(string $name, array $grants): User
    {
        return $this->makePerson($this->org, $name, $this->unit, [
            $this->makeRole($this->org, $name, $grants),
        ]);
    }

    private function graph(): array
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $this->handOver($this->org, $asset, ['org_unit_id' => $this->unit->id], $this->manager);
        $vehicle = $this->makeVehicle($this->org, $asset, $this->manager);

        return [$asset, $vehicle,
            $this->makeJob($this->org, $asset, $this->manager),
            $this->makeCertificate($this->org, $vehicle, $this->manager),
            $this->logFuel($this->org, $vehicle, $this->manager, ['odometer' => 100100]),
        ];
    }

    private function assertAccess(User $viewer, Model $record, bool $expected): void
    {
        $this->withinOrganization($this->org, function () use ($viewer, $record, $expected) {
            app(AccessResolver::class)->flushMemo();
            $this->assertSame($expected, Gate::forUser($viewer)->allows('view', $record), $record::class.' policy');
            $this->assertSame($expected, $record::visibleTo($viewer)->whereKey($record->id)->exists(), $record::class.' list');
        });
    }

    private function acl(User $viewer, Model $record, string $ability, string $effect): void
    {
        DB::table('record_permissions')->insert([
            'organization_id' => $this->org->id, 'record_type' => $record->getMorphClass(),
            'record_id' => $record->id, 'grantee_type' => 'user', 'grantee_id' => $viewer->id,
            'ability' => $ability, 'effect' => $effect, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function confidential_assets_cannot_be_read_through_child_records_or_child_shares(): void
    {
        [$asset, $vehicle, $job, $document, $fuel] = $this->graph();
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['confidentiality' => 'confidential'])->save());
        foreach ([$vehicle, $job, $document, $fuel] as $child) {
            $this->acl($this->manager, $child, $child::defaultViewPermission(), 'allow');
            $this->assertAccess($this->manager, $child, false);
        }
        $this->actingFresh($this->manager)->get(route('vehicles.show', $vehicle))->assertForbidden();
        $this->actingFresh($this->manager)->get(route('vehicles.index', ['view' => 'all']))
            ->assertOk()->assertDontSee($vehicle->registration_number);
    }

    #[Test]
    public function a_parent_deny_wins_over_organization_scope_and_explicit_child_shares(): void
    {
        [$asset, $vehicle, $job, $document, $fuel] = $this->graph();
        $this->acl($this->manager, $asset, 'asset.view', 'deny');
        foreach ([$vehicle, $job, $document, $fuel] as $child) {
            $this->acl($this->manager, $child, '*', 'allow');
            $this->assertAccess($this->manager, $child, false);
        }
    }

    #[Test]
    public function department_grants_follow_the_asset_without_becoming_organization_wide(): void
    {
        [$asset, $vehicle, $job, $document, $fuel] = $this->graph();
        $viewer = $this->viewer('Planner', array_fill_keys([
            'vehicle.view', 'asset_maintenance.view', 'vehicle_document.view', 'fuel_log.view',
        ], 'department'));
        foreach ([$vehicle, $job, $document, $fuel] as $child) {
            $this->assertAccess($viewer, $child, true);
        }
        $other = $this->makeUnit($this->org, 'Other department', OrgUnitType::Department);
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['org_unit_id' => $other->id])->save());
        foreach ([$vehicle, $job, $document, $fuel] as $child) {
            $this->assertAccess($viewer, $child, false);
        }
    }

    #[Test]
    public function project_grants_follow_the_asset_and_maintenance_filter_uses_that_project(): void
    {
        [$asset, $vehicle, $job, $document] = $this->graph();
        $viewer = $this->viewer('Project reader', array_fill_keys([
            'vehicle.view', 'asset_maintenance.view', 'vehicle_document.view',
        ], 'project'));
        $project = $this->makeProject($this->org, $viewer, 'Assigned project', ['manager_id' => $viewer->id]);
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['project_id' => $project->id])->save());
        foreach ([$vehicle, $job, $document] as $child) {
            $this->assertAccess($viewer, $child, true);
        }
        $this->actingFresh($this->manager)->get(route('asset-maintenances.index', [
            'project' => $project->id, 'view' => 'all',
        ]))->assertOk()->assertSee($job->reference);
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['project_id' => null])->save());
        foreach ([$vehicle, $job, $document] as $child) {
            $this->assertAccess($viewer, $child, false);
        }
    }

    #[Test]
    public function a_driver_keeps_assigned_vehicle_access_without_asset_register_permission(): void
    {
        [$asset, $vehicle] = $this->graph();
        $driver = $this->viewer('Driver', ['vehicle.view' => 'assigned']);
        $this->assertAccess($driver, $vehicle, false);
        $this->withinOrganization($this->org, fn () => $vehicle->forceFill(['assigned_driver_id' => $driver->id])->save());
        $this->assertAccess($driver, $vehicle, true);
        $this->assertAccess($driver, $asset, false);
        $this->acl($driver, $asset, 'asset.view', 'deny');
        $this->assertAccess($driver, $vehicle, false);
    }

    #[Test]
    public function parent_shares_do_not_grant_a_missing_child_permission(): void
    {
        [$asset, $vehicle] = $this->graph();
        $viewer = $this->viewer('Asset reader', ['asset.view' => 'organization']);
        $this->acl($viewer, $asset, '*', 'allow');
        $this->assertAccess($viewer, $vehicle, false);
    }

    #[Test]
    public function a_parent_share_can_lift_parent_confidentiality_but_not_a_child_deny(): void
    {
        [$asset, $vehicle] = $this->graph();
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['confidentiality' => 'confidential'])->save());
        $this->acl($this->manager, $asset, 'asset.view', 'allow');
        $this->assertAccess($this->manager, $vehicle, true);
        $this->acl($this->manager, $vehicle, 'vehicle.view', 'deny');
        $this->assertAccess($this->manager, $vehicle, false);
    }

    #[Test]
    public function a_foreign_parent_is_hidden_even_when_the_child_has_the_local_tenant_id(): void
    {
        [$asset, $vehicle] = $this->graph();
        $other = $this->makeOrganization('Other company');
        DB::table('assets')->where('id', $asset->id)->update(['organization_id' => $other->id]);
        $this->assertAccess($this->manager, $vehicle, false);
    }

    #[Test]
    public function a_project_pinned_role_cannot_escape_through_a_child_without_a_project_column(): void
    {
        [$asset, $vehicle, $job, $document] = $this->graph();
        $project = $this->makeProject($this->org, $this->manager, 'Pinned project');
        $viewer = $this->viewer('Pinned reader', array_fill_keys([
            'vehicle.view', 'asset_maintenance.view', 'vehicle_document.view',
        ], 'organization'));
        DB::table('role_user')->where('user_id', $viewer->id)->update(['scope_type' => 'project', 'scope_id' => $project->id]);
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['project_id' => $project->id])->save());
        foreach ([$vehicle, $job, $document] as $child) {
            $this->assertAccess($viewer, $child, true);
        }
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['project_id' => null])->save());
        foreach ([$vehicle, $job, $document] as $child) {
            $this->assertAccess($viewer, $child, false);
        }
    }

    #[Test]
    public function restricted_job_classification_uses_the_assets_owning_department(): void
    {
        [$asset, $vehicle, $job] = $this->graph();
        $this->withinOrganization($this->org, fn () => $job->forceFill(['confidentiality' => 'restricted'])->save());
        $this->assertAccess($this->manager, $job, true);
        $other = $this->makeUnit($this->org, 'Outside', OrgUnitType::Department);
        $this->withinOrganization($this->org, fn () => $asset->forceFill(['org_unit_id' => $other->id])->save());
        $this->assertAccess($this->manager, $job, false);
    }
}
