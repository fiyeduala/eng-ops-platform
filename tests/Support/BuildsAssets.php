<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Configuration\SettingsRepository;
use App\Models\Asset;
use App\Models\AssetCustodyTransfer;
use App\Models\AssetMaintenance;
use App\Models\FuelLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\AssetMaintenanceService;
use App\Services\AssetService;
use App\Services\FleetService;
use App\Services\VehicleDocumentService;

/**
 * Fixtures for the yard.
 *
 * SAME RULE AS {@see BuildsInventory}: everything is made the way the application makes it. No test
 * here inserts a custody row, a fuel log or an odometer reading directly, because all three are
 * caches or trails maintained by a service — a fixture that wrote `assets.custodian_id` by hand
 * would let a test assert a custody the trail does not support, which is precisely the disagreement
 * {@see Asset::custodyMatchesTrail()} exists to catch.
 *
 * `makeAsset()` COMMISSIONS. An asset is created in `draft` — "being registered" — and almost every
 * test wants a thing that is actually in use rather than that ceremony. Pass `commission: false` to
 * get the draft and make the decision yourself.
 *
 * Companion to {@see BuildsTenants}, which this assumes.
 */
trait BuildsAssets
{
    /** @param  array<string,mixed>  $attributes */
    protected function makeAsset(
        Organization $organization,
        User $actor,
        string $code = 'GEN-01',
        array $attributes = [],
        bool $commission = true,
    ): Asset {
        $this->provisionConfiguration($organization);

        /** @var Asset */
        return $this->withinOrganization($organization, function () use ($code, $attributes, $actor, $commission) {
            $assets = app(AssetService::class);

            /** @var Asset $asset */
            $asset = $assets->create([
                'code' => $code,
                'name' => '250 kVA diesel generator',
                'category' => 'Generator',
                'manufacturer' => 'Perkins',
                'serial_number' => 'PK-'.$code,
                'acquired_on' => now()->subYear()->toDateString(),
                'purchase_cost' => 18500000,
                ...$attributes,
            ], $actor);

            return $commission ? $assets->commission($asset, $actor) : $asset;
        });
    }

    /**
     * Hand the thing to somebody.
     *
     * The authoriser defaults to the actor because most tests here are not about the second
     * signature; the ones that are pass `authorisedBy: null` explicitly and assert the refusal.
     *
     * @param  array<string,mixed>  $to
     */
    protected function handOver(
        Organization $organization,
        Asset $asset,
        array $to,
        User $actor,
        ?int $authorisedBy = -1,
    ): AssetCustodyTransfer {
        return $this->withinOrganization($organization, fn () => app(AssetService::class)->transferCustody(
            $asset,
            $to,
            $actor,
            'Fixture handover',
            'good',
            null,
            $authorisedBy === -1 ? $actor->getKey() : $authorisedBy,
        ));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeVehicle(
        Organization $organization,
        Asset $asset,
        User $actor,
        array $attributes = [],
    ): Vehicle {
        return $this->withinOrganization($organization, fn () => app(FleetService::class)->attachVehicle(
            $asset,
            [
                'registration_number' => 'LSR 482 XA',
                'vehicle_type' => 'Tipper truck',
                'fuel_type' => 'Diesel',
                'year_of_manufacture' => 2019,
                'tank_capacity' => 300,
                'current_odometer' => 100000,
                ...$attributes,
            ],
            $actor,
        ));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function logFuel(
        Organization $organization,
        Vehicle $vehicle,
        User $actor,
        array $attributes = [],
    ): FuelLog {
        return $this->withinOrganization($organization, fn () => app(FleetService::class)->logFuel(
            $vehicle,
            [
                'filled_at' => now()->subDays(3),
                'litres' => 180,
                'total_cost' => 257400,
                'station' => 'Ardova, Sagamu Road',
                ...$attributes,
            ],
            $actor,
        ));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeJob(
        Organization $organization,
        Asset $asset,
        User $actor,
        array $attributes = [],
    ): AssetMaintenance {
        /** @var AssetMaintenance */
        return $this->withinOrganization($organization, fn () => app(AssetMaintenanceService::class)->create([
            'asset_id' => $asset->getKey(),
            'title' => '20,000 km service',
            'is_planned' => true,
            'maintenance_type' => 'Routine service',
            'due_on' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ], $actor));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeCertificate(
        Organization $organization,
        Vehicle $vehicle,
        User $actor,
        array $attributes = [],
    ): VehicleDocument {
        return $this->withinOrganization($organization, fn () => app(VehicleDocumentService::class)->create(
            $vehicle,
            [
                'document_type' => 'Insurance',
                'document_number' => 'LWA/CV/0092841',
                'issuer' => 'Leadway Assurance',
                'issued_on' => now()->subMonths(6)->toDateString(),
                'expires_on' => now()->addMonths(6)->toDateString(),
                ...$attributes,
            ],
            $actor,
        ));
    }

    /** A per-organization setting, written the way the settings screen writes it. */
    protected function assetSetting(Organization $organization, string $key, mixed $value): void
    {
        $this->withinOrganization(
            $organization,
            fn () => app(SettingsRepository::class)->set($key, $value, $organization->id, 'assets')
        );
    }

    /**
     * Every ability the fleet-manager template carries, at organization scope.
     *
     * Spelled out rather than read from RoleTemplates, because a test that took its grants from the
     * same constant the application does would keep passing if somebody removed an ability from
     * that template — the grants and the assertions would move together and the test would report
     * agreement rather than correctness.
     *
     * @return array<string,string>
     */
    protected function fleetGrants(): array
    {
        $grants = [];

        foreach ([
            'asset.view', 'asset.create', 'asset.edit', 'asset.delete', 'asset.manage',
            'asset.assign', 'asset.transfer', 'asset.dispose',
            'asset_maintenance.view', 'asset_maintenance.create', 'asset_maintenance.edit',
            'asset_maintenance.delete', 'asset_maintenance.manage', 'asset_maintenance.complete',
            'vehicle.view', 'vehicle.create', 'vehicle.edit', 'vehicle.delete', 'vehicle.manage',
            'vehicle.assign',
            'fuel_log.view', 'fuel_log.create', 'fuel_log.edit', 'fuel_log.delete', 'fuel_log.manage',
            'vehicle_document.view', 'vehicle_document.create', 'vehicle_document.edit',
            'vehicle_document.delete', 'vehicle_document.manage', 'vehicle_document.renew',
            'org_unit.view', 'location.view', 'project.view', 'employee.view',
        ] as $key) {
            $grants[$key] = 'organization';
        }

        return $grants;
    }
}
