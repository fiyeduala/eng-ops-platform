<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AssetMaintenanceService;
use App\Services\FleetService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The lorries, what they burn, and the days they spend in the workshop.
 *
 * THE ODOMETER IS THE SPINE OF THIS FILE. `vehicles.current_odometer` is a cache of the highest
 * reading anybody has recorded, and readings arrive from two registers — a fuel receipt and a
 * completed job card. If those two disagree the vehicle has two mileages, and every service
 * interval computed from either is wrong by an unknown amount. So the reading is refused when it
 * goes backwards, and a completed job feeds the same counter a fill does (§90).
 *
 * CONSUMPTION IS ALLOWED TO BE NULL. A fleet report that prints a figure for a van whose driver
 * never writes the mileage down is not being helpful, it is guessing — and a guess that looks like
 * a measurement is the worst of the three possible answers. The setting that makes the reading
 * optional is a real company's real decision, and this file asserts what it costs rather than
 * pretending it costs nothing (§73).
 *
 * AND A COMPLETED SERVICE PUTS THE MACHINE BACK TO WORK. That sounds like housekeeping; it was in
 * fact broken, and the last test here is the one that caught it.
 */
final class FleetTest extends TestCase
{
    use BuildsAssets, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private User $fleetManager;

    private User $driver;

    private OrgUnit $fleet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->fleet = $this->makeUnit($this->meridian, 'Fleet & Logistics', OrgUnitType::Department);

        $role = $this->makeRole($this->meridian, 'Fleet Manager', $this->fleetGrants());

        $this->fleetManager = $this->makePerson($this->meridian, 'Tunde Ogunleye', $this->fleet, [$role]);
        $this->driver = $this->makePerson($this->meridian, 'Musa Garba', $this->fleet, [$role]);
    }

    #[Test]
    public function a_vehicle_is_an_asset_and_says_so_on_both_sides(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01', ['name' => 'Tipper truck']);
        $vehicle = $this->makeVehicle($this->meridian, $asset, $this->fleetManager);

        // One thing, two registers, and the flag on the asset is what lets the asset register answer
        // "which of these are on the road" without joining. A vehicle row with no asset behind it
        // would have no custody, no cost and no disposal — three of the four questions an auditor
        // asks about a lorry.
        $this->assertTrue((bool) $this->reloadedAsset($asset)->is_vehicle);
        $this->assertSame($asset->getKey(), $vehicle->asset_id);
    }

    #[Test]
    public function the_plate_is_stored_the_way_a_person_would_search_for_it(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01');
        $vehicle = $this->makeVehicle($this->meridian, $asset, $this->fleetManager, [
            'registration_number' => 'lsr 482-xa',
        ]);

        // Spaces and dashes are how a plate is painted, not how it is looked up. Two rows reading
        // "LSR 482 XA" and "lsr482xa" are the same lorry entered twice, and a fleet register that
        // permits both cannot tell anybody how many vehicles the company has.
        $this->assertSame('LSR482XA', $vehicle->registration_number);
    }

    #[Test]
    public function the_same_plate_cannot_be_registered_twice(): void
    {
        $first = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01');
        $this->makeVehicle($this->meridian, $first, $this->fleetManager);

        $second = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-02');

        $this->expectException(QueryException::class);

        $this->makeVehicle($this->meridian, $second, $this->fleetManager);
    }

    #[Test]
    public function a_fill_moves_the_odometer_and_derives_the_unit_cost(): void
    {
        $vehicle = $this->fleetVehicle(['current_odometer' => 213100]);

        $log = $this->logFuel($this->meridian, $vehicle, $this->driver, [
            'litres' => 185,
            'odometer' => 213400,
            'total_cost' => 259000,
        ]);

        // Derived rather than typed: a driver hands in a receipt with litres and a total on it, and
        // a price per litre somebody keyed in a fourth time is a fourth chance to be wrong.
        $this->assertSame(1400.0, (float) $log->unit_cost);
        $this->assertSame(213400, $this->reloadedVehicle($vehicle)->odometer());
    }

    #[Test]
    public function a_reading_that_goes_backwards_is_refused(): void
    {
        $vehicle = $this->fleetVehicle(['current_odometer' => 213100]);

        $this->logFuel($this->meridian, $vehicle, $this->driver, ['odometer' => 213400]);

        // Odometers do not run down. A reading below the one on file is a typo, a different lorry,
        // or a tampered instrument, and all three are things somebody should be told about rather
        // than a number the system quietly accepts and then computes a negative distance from.
        $this->expectException(DomainException::class);

        $this->logFuel($this->meridian, $this->reloadedVehicle($vehicle), $this->driver, [
            'odometer' => 213000,
        ]);
    }

    #[Test]
    public function consumption_needs_two_full_tanks_and_says_nothing_before_that(): void
    {
        $vehicle = $this->fleetVehicle(['current_odometer' => 213100]);

        $this->logFuel($this->meridian, $vehicle, $this->driver, [
            'odometer' => 213400, 'litres' => 185, 'total_cost' => 259000,
        ]);

        // One fill is a receipt, not an interval. There is no distance between a reading and
        // nothing, and a figure computed from one is arithmetic on an assumption.
        $this->assertNull($this->reloadedVehicle($vehicle)->consumptionPer100());

        $this->logFuel($this->meridian, $this->reloadedVehicle($vehicle), $this->driver, [
            'odometer' => 213900, 'litres' => 160, 'total_cost' => 224000,
        ]);

        // 160 litres over 500 km. Not a round number on purpose — 32.0 would pass on code that
        // divided by the wrong distance and happened to land.
        $this->assertSame(32.0, $this->reloadedVehicle($vehicle)->consumptionPer100());
    }

    #[Test]
    public function a_company_that_does_not_require_the_odometer_gets_spend_and_no_consumption(): void
    {
        // Kestrel's decision, asserted rather than described. The fills are real, the money is real,
        // and the consumption is honestly absent — which is the whole point of letting a company
        // relax the rule instead of inventing a reading for it (§73).
        $this->assetSetting($this->meridian, 'fuel_log.odometer_required', false);

        $vehicle = $this->fleetVehicle(['current_odometer' => null]);

        $this->logFuel($this->meridian, $vehicle, $this->driver, ['litres' => 58.4, 'total_cost' => 76.51]);
        $this->logFuel($this->meridian, $this->reloadedVehicle($vehicle), $this->driver, [
            'litres' => 61.9, 'total_cost' => 80.47,
        ]);

        $reloaded = $this->reloadedVehicle($vehicle);

        $this->assertNull($reloaded->odometer());
        $this->assertNull($reloaded->consumptionPer100());
        $this->assertSame(2, $reloaded->fuelLogs->count());
    }

    #[Test]
    public function a_company_that_does_require_it_refuses_a_fill_without_one(): void
    {
        $this->assetSetting($this->meridian, 'fuel_log.odometer_required', true);

        $vehicle = $this->fleetVehicle(['current_odometer' => 213100]);

        $this->expectException(DomainException::class);

        $this->logFuel($this->meridian, $vehicle, $this->driver, ['litres' => 185, 'total_cost' => 259000]);
    }

    #[Test]
    public function starting_a_job_takes_the_machine_out_of_service_and_completing_it_puts_it_back(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01');

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->driver->getKey(),
            'org_unit_id' => $this->fleet->id,
        ], $this->fleetManager);

        $vehicle = $this->makeVehicle($this->meridian, $this->reloadedAsset($asset), $this->fleetManager, [
            'current_odometer' => 213100,
        ]);

        $job = $this->makeJob($this->meridian, $this->reloadedAsset($asset), $this->fleetManager);

        $this->withinOrganization($this->meridian, fn () => app(AssetMaintenanceService::class)
            ->start($this->reloadedJob($job), $this->fleetManager, 214780));

        $this->assertSame('under_maintenance', $this->reloadedAsset($asset)->statusKey());

        $this->withinOrganization($this->meridian, fn () => app(AssetMaintenanceService::class)
            ->complete($this->reloadedJob($job), [
                'labour_cost' => 45000,
                'parts_cost' => 128500,
                'downtime_hours' => 6.5,
                'odometer' => 214780,
                'findings' => 'Nearside rear brake chamber weeping and replaced.',
            ], $this->fleetManager));

        /*
         * THE ASSERTION THAT FOUND THE BUG.
         *
         * `complete()` returns the machine to service only if it is still where this job put it,
         * and that guard reads `statusKey()` — which reads the loaded `status` RELATION, not the
         * column. `start()` wrote the column and left the relation alone, so by the time
         * `complete()` asked, the asset still claimed the status it held before the job began, the
         * guard never matched, and every completed service left its machine parked in
         * `under_maintenance` for good. The demo seed showed a whole yard in the workshop.
         */
        $this->assertSame('in_service', $this->reloadedAsset($asset)->statusKey());

        // And the reading on the job card is the same fact as the reading on the vehicle. One
        // number, recorded once, visible from both registers.
        $this->assertSame(214780, $this->reloadedVehicle($vehicle)->odometer());
    }

    #[Test]
    public function a_completed_job_carries_what_the_work_cost_and_what_it_cost_in_time(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01');
        $job = $this->makeJob($this->meridian, $asset, $this->fleetManager);

        $this->withinOrganization($this->meridian, function () use ($job) {
            $jobs = app(AssetMaintenanceService::class);
            $jobs->start($this->reloadedJob($job), $this->fleetManager);
            $jobs->complete($this->reloadedJob($job), [
                'labour_cost' => 45000,
                'parts_cost' => 128500,
                'downtime_hours' => 6.5,
            ], $this->fleetManager);
        });

        $completed = $this->reloadedJob($job);

        $this->assertSame('completed', $completed->statusKey());
        $this->assertSame(6.5, (float) $completed->downtime_hours);
        $this->assertSame(173500.0, (float) $completed->labour_cost + (float) $completed->parts_cost);
        $this->assertNotNull($completed->completed_at);
    }

    #[Test]
    public function an_overdue_job_is_a_date_question_and_not_a_stored_flag(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01');

        $overdue = $this->makeJob($this->meridian, $asset, $this->fleetManager, [
            'title' => 'Annual service',
            'due_on' => now()->subDays(9)->toDateString(),
        ]);

        $soon = $this->makeJob($this->meridian, $asset, $this->fleetManager, [
            'title' => 'Tyre change',
            'due_on' => now()->addDays(12)->toDateString(),
        ]);

        // Nothing has swept a flag onto either row and nothing needs to. A missed cron costs a
        // warning email, not the truth about which machine is out of ticket.
        $this->assertTrue($this->reloadedJob($overdue)->isOverdue());
        $this->assertFalse($this->reloadedJob($soon)->isOverdue());

        // And closing it stops it being overdue, without anybody editing the due date. A job that
        // stayed red after it was done would train the planner to ignore red.
        $this->withinOrganization($this->meridian, function () use ($overdue) {
            $jobs = app(AssetMaintenanceService::class);
            $jobs->start($this->reloadedJob($overdue), $this->fleetManager);
            $jobs->complete($this->reloadedJob($overdue), [], $this->fleetManager);
        });

        $this->assertFalse($this->reloadedJob($overdue)->isOverdue());
    }

    #[Test]
    public function assigning_a_driver_is_not_the_same_as_handing_over_custody(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01');

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->fleetManager->getKey(),
            'org_unit_id' => $this->fleet->id,
        ], $this->fleetManager);

        $vehicle = $this->makeVehicle($this->meridian, $this->reloadedAsset($asset), $this->fleetManager);

        $this->withinOrganization($this->meridian, fn () => app(FleetService::class)
            ->assignDriver($this->reloadedVehicle($vehicle), $this->driver->getKey(), $this->fleetManager));

        // Musa drives it; Tunde answers for it. Conflating the two would leave a lorry parked for a
        // fortnight with nobody responsible for it, and would make "who had the vehicle when it was
        // damaged" a question about the rota rather than about the register.
        $this->assertSame($this->driver->getKey(), $this->reloadedVehicle($vehicle)->assigned_driver_id);
        $this->assertSame($this->fleetManager->getKey(), $this->reloadedAsset($asset)->custodian_id);
    }

    // ------------------------------------------------------------------ fixtures

    /** @param  array<string,mixed>  $attributes */
    private function fleetVehicle(array $attributes = []): Vehicle
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager, 'TRK-01', ['name' => 'Tipper truck']);

        return $this->makeVehicle($this->meridian, $asset, $this->fleetManager, $attributes);
    }

    /** Read inside the tenant, for the reason {@see AssetCustodyTest::reloaded()} sets out. */
    private function reloadedAsset(Asset $asset): Asset
    {
        /** @var Asset */
        return $this->withinOrganization(
            $this->meridian,
            fn () => Asset::query()->with(['status', 'custodyTransfers'])->findOrFail($asset->getKey())
        );
    }

    private function reloadedVehicle(Vehicle $vehicle): Vehicle
    {
        /** @var Vehicle */
        return $this->withinOrganization(
            $this->meridian,
            fn () => Vehicle::query()->with(['fuelLogs', 'documents'])->findOrFail($vehicle->getKey())
        );
    }

    private function reloadedJob(AssetMaintenance $job): AssetMaintenance
    {
        /** @var AssetMaintenance */
        return $this->withinOrganization(
            $this->meridian,
            fn () => AssetMaintenance::query()->with(['status', 'asset.status'])->findOrFail($job->getKey())
        );
    }
}
