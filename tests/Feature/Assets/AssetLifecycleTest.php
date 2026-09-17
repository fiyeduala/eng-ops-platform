<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AssetMaintenance;
use App\Models\Organization;
use App\Models\Status;
use App\Models\User;
use App\Services\AssetMaintenanceService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class AssetLifecycleTest extends TestCase
{
    use BuildsAssets, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Fleet lifecycle');
        $this->manager = $this->makePerson($this->org, 'Manager', null, [
            $this->makeRole($this->org, 'Fleet manager', $this->fleetGrants()),
        ]);
    }

    #[Test]
    public function partial_fills_between_full_tanks_are_included_but_later_fills_are_not(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $vehicle = $this->makeVehicle($this->org, $asset, $this->manager);
        foreach ([[100100, 60, true], [100300, 20, false], [100600, 30, true], [100700, 12, false]] as [$reading, $litres, $full]) {
            $this->logFuel($this->org, $vehicle, $this->manager, [
                'odometer' => $reading, 'litres' => $litres, 'is_full_tank' => $full,
            ]);
        }
        $this->withinOrganization($this->org, function () use ($vehicle) {
            $vehicle->load('fuelLogs');
            $this->assertSame(10.0, $vehicle->consumptionPer100());
        });
    }

    #[Test]
    public function a_stale_vehicle_cannot_reduce_the_clock_or_leave_a_rejected_fuel_log(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $vehicle = $this->makeVehicle($this->org, $asset, $this->manager);
        $stale = clone $vehicle;
        $this->logFuel($this->org, $vehicle, $this->manager, ['odometer' => 101000]);
        try {
            $this->logFuel($this->org, $stale, $this->manager, ['odometer' => 100500]);
            $this->fail('The stale reading was accepted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('never goes backwards', $e->getMessage());
        }
        $this->withinOrganization($this->org, function () use ($vehicle) {
            $this->assertSame(101000, $vehicle->fresh()->odometer());
            $this->assertSame(1, $vehicle->fuelLogs()->count());
        });
    }

    #[Test]
    public function custody_from_a_stale_instance_continues_the_latest_handover(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $stale = clone $asset;
        $other = $this->makePerson($this->org, 'Next custodian');
        $this->handOver($this->org, $asset, ['custodian_id' => $this->manager->id], $this->manager);
        $transfer = $this->handOver($this->org, $stale, ['custodian_id' => $other->id], $this->manager);
        $this->assertSame($this->manager->id, $transfer->from_custodian_id);
        $this->assertSame($other->id, $transfer->to_custodian_id);
    }

    #[Test]
    public function completing_one_job_does_not_release_a_machine_with_another_job_in_progress(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $first = $this->makeJob($this->org, $asset, $this->manager);
        $second = $this->makeJob($this->org, $asset, $this->manager, ['title' => 'Second repair']);
        $this->withinOrganization($this->org, function () use ($asset, $first, $second) {
            $service = app(AssetMaintenanceService::class);
            $service->start($first, $this->manager);
            $service->start($second, $this->manager);
            $service->complete($first, [], $this->manager);
            $this->assertSame('under_maintenance', $asset->fresh('status')->statusKey());
            $service->complete($second, [], $this->manager);
            $this->assertSame('idle', $asset->fresh('status')->statusKey());
        });
    }

    #[Test]
    public function reopening_requires_a_reason_and_resets_completion_and_availability(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $job = $this->makeJob($this->org, $asset, $this->manager);
        $target = $this->withinOrganization($this->org, function () use ($job) {
            $service = app(AssetMaintenanceService::class);
            $service->start($job, $this->manager);
            $service->complete($job, ['downtime_hours' => 3], $this->manager);

            return Status::forEntity('asset_maintenance')->where('key', 'in_progress')->value('id');
        });
        $url = route('asset-maintenances.transition', $job);
        $this->actingFresh($this->manager)->post($url, ['status_id' => $target])->assertSessionHasErrors('status_id');
        $this->actingFresh($this->manager)->post($url, ['status_id' => $target, 'comment' => 'Repair failed its inspection'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->withinOrganization($this->org, function () use ($job, $asset) {
            $fresh = $job->fresh('status');
            $this->assertNull($fresh->completed_at);
            $this->assertNull($fresh->downtime_hours);
            $this->assertTrue($fresh->isInProgress());
            $this->assertSame('under_maintenance', $asset->fresh('status')->statusKey());
        });
    }

    #[Test]
    public function cancelling_the_last_active_job_releases_the_machine(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $job = $this->makeJob($this->org, $asset, $this->manager);
        $this->withinOrganization($this->org, function () use ($asset, $job) {
            $service = app(AssetMaintenanceService::class);
            $service->start($job, $this->manager);
            $service->changeStatus($job, Status::forEntity('asset_maintenance')->where('key', 'cancelled')->firstOrFail(), $this->manager, 'Work no longer needed');
            $this->assertFalse($job->isInProgress());
            $this->assertSame('idle', $asset->fresh('status')->statusKey());
        });
    }

    #[Test]
    public function external_work_order_references_survive_create_and_edit_forms(): void
    {
        $asset = $this->makeAsset($this->org, $this->manager);
        $data = ['asset_id' => $asset->id, 'title' => 'Service', 'confidentiality' => 'internal', 'work_order_reference' => 'EXT-001'];
        $this->actingFresh($this->manager)->post(route('asset-maintenances.store'), $data)
            ->assertSessionHasNoErrors()->assertRedirect();
        $job = $this->withinOrganization($this->org, fn () => AssetMaintenance::query()->latest('id')->firstOrFail());
        $this->assertSame('EXT-001', $job->work_order_reference);
        unset($data['asset_id']);
        $data['work_order_reference'] = 'EXT-002';
        $this->actingFresh($this->manager)->put(route('asset-maintenances.update', $job), $data)
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('asset_maintenances', ['id' => $job->id, 'work_order_reference' => 'EXT-002']);
    }
}
