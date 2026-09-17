<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetCustodyTransfer;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AssetService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Who has the generator, and how we know.
 *
 * THE FOUR CUSTODY COLUMNS ON `assets` ARE A CACHE OF A TRAIL. `custodian_id`, `org_unit_id`,
 * `project_id` and `location_id` are there so a register page can be drawn without a subquery per
 * row; the record is the append-only `asset_custody_transfers` table. Every assertion in this file
 * exists to keep those two in agreement, and the last one throws the cache away and rebuilds it
 * from the trail — because a custody field somebody can type is a custody field somebody can type
 * back, and then nobody knows who had the machine.
 *
 * THE SECOND SIGNATURE IS A SETTING AND NOT A RULE (§73). One company requires an authoriser on
 * every handover; a company of eight, where the person handing over and the person permitting it
 * are the same person, does not — and the same code has to serve both without either being a
 * special case. Both directions are asserted here.
 */
final class AssetCustodyTest extends TestCase
{
    use BuildsAssets, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private User $fleetManager;

    private User $siteManager;

    private OrgUnit $fleet;

    private OrgUnit $construction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->fleet = $this->makeUnit($this->meridian, 'Fleet & Logistics', OrgUnitType::Department);
        $this->construction = $this->makeUnit($this->meridian, 'Construction', OrgUnitType::Department);

        $role = $this->makeRole($this->meridian, 'Fleet Manager', $this->fleetGrants());

        $this->fleetManager = $this->makePerson($this->meridian, 'Tunde Ogunleye', $this->fleet, [$role]);
        $this->siteManager = $this->makePerson($this->meridian, 'Kelechi Nwosu', $this->construction, [$role]);
    }

    #[Test]
    public function a_new_asset_is_in_nobodys_hands(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        // Not zero, not the creator, and not the creator's department. An asset register that quietly
        // assumed the person typing it in is holding the thing would be wrong about every machine
        // that arrives on a lorry while the storekeeper is at a desk.
        $this->assertNull($asset->custodian_id);
        $this->assertNull($asset->org_unit_id);
        $this->assertNull($asset->location_id);
        $this->assertSame(0, $this->trailOf($asset)->count());
    }

    #[Test]
    public function handing_it_over_writes_the_trail_and_the_cache_together(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->siteManager->getKey(),
            'org_unit_id' => $this->construction->id,
        ], $this->fleetManager);

        $asset->refresh();

        $this->assertSame($this->siteManager->getKey(), $asset->custodian_id);
        $this->assertSame($this->construction->id, $asset->org_unit_id);

        $transfer = $this->trailOf($asset)->firstOrFail();

        // The FROM side matters as much as the TO side: "it went from nobody to Kelechi" is a
        // different sentence from "it went from Tunde to Kelechi", and only one of them is true.
        $this->assertNull($transfer->from_custodian_id);
        $this->assertSame($this->siteManager->getKey(), $transfer->to_custodian_id);
        $this->assertSame($this->fleetManager->getKey(), $transfer->authorised_by);
    }

    #[Test]
    public function a_second_move_starts_where_the_first_one_finished(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->fleetManager->getKey(),
            'org_unit_id' => $this->fleet->id,
        ], $this->fleetManager);

        $this->handOver($this->meridian, $this->reloaded($asset), [
            'custodian_id' => $this->siteManager->getKey(),
            'org_unit_id' => $this->construction->id,
        ], $this->fleetManager);

        $trail = $this->trailOf($asset)->reverse()->values();

        $this->assertCount(2, $trail);
        $this->assertNull($trail[0]->from_custodian_id);
        $this->assertSame($this->fleetManager->getKey(), $trail[0]->to_custodian_id);

        // The join. A chain whose second link does not begin where the first one ended is not a
        // chain, and a register built from it would report a machine in two places.
        $this->assertSame($trail[0]->to_custodian_id, $trail[1]->from_custodian_id);
        $this->assertSame($this->siteManager->getKey(), $trail[1]->to_custodian_id);
    }

    #[Test]
    public function handing_it_to_where_it_already_is_records_nothing(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->siteManager->getKey(),
            'org_unit_id' => $this->construction->id,
        ], $this->fleetManager);

        // Refused rather than recorded, because a trail padded with moves that moved nothing is a
        // trail nobody reads — and the register's "last moved" date would then mean the last time
        // somebody pressed the button rather than the last time the machine went anywhere.
        $this->expectException(DomainException::class);

        $this->handOver($this->meridian, $this->reloaded($asset), [
            'custodian_id' => $this->siteManager->getKey(),
            'org_unit_id' => $this->construction->id,
        ], $this->fleetManager);
    }

    #[Test]
    public function a_company_that_requires_a_second_signature_refuses_a_handover_without_one(): void
    {
        $this->assetSetting($this->meridian, 'asset.require_authoriser_on_transfer', true);

        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->expectException(DomainException::class);

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->siteManager->getKey(),
        ], $this->fleetManager, authorisedBy: null);
    }

    #[Test]
    public function a_company_that_does_not_require_one_accepts_the_same_handover(): void
    {
        // The identical call, one setting apart. This is the §73 assertion: configuration is what
        // separates the two companies, not a branch somebody wrote for one of them.
        $this->assetSetting($this->meridian, 'asset.require_authoriser_on_transfer', false);

        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $transfer = $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->siteManager->getKey(),
        ], $this->fleetManager, authorisedBy: null);

        $this->assertNull($transfer->authorised_by);
        $this->assertSame($this->siteManager->getKey(), $this->reloaded($asset)->custodian_id);
    }

    #[Test]
    public function custody_cannot_be_typed_onto_the_asset_through_an_ordinary_edit(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->actingFresh($this->fleetManager)
            ->put(route('assets.update', $asset), [
                'code' => $asset->code,
                'name' => $asset->name,
                'category' => $asset->category,
                // §84: the form does not offer these, so a request that carries them anyway is
                // somebody walking round the handover screen. The service's protected list is what
                // stops it, and this asserts that over HTTP rather than in a unit test.
                'custodian_id' => $this->siteManager->getKey(),
                'org_unit_id' => $this->construction->id,
            ])
            ->assertRedirect();

        $asset->refresh();

        $this->assertNull($asset->custodian_id);
        $this->assertNull($asset->org_unit_id);
        $this->assertSame(0, $this->trailOf($asset)->count());
    }

    #[Test]
    public function a_retired_asset_cannot_be_handed_to_anybody(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->fleetManager->getKey(),
        ], $this->fleetManager);

        $this->withinOrganization($this->meridian, fn () => app(AssetService::class)
            ->retire($this->reloaded($asset), $this->fleetManager, 'DISP-0001', 'Sold at auction'));

        $this->expectException(DomainException::class);

        $this->handOver($this->meridian, $this->reloaded($asset), [
            'custodian_id' => $this->siteManager->getKey(),
        ], $this->fleetManager);
    }

    #[Test]
    public function the_cache_can_be_rebuilt_from_the_trail_alone(): void
    {
        $asset = $this->makeAsset($this->meridian, $this->fleetManager);

        $this->handOver($this->meridian, $asset, [
            'custodian_id' => $this->fleetManager->getKey(),
            'org_unit_id' => $this->fleet->id,
        ], $this->fleetManager);

        $this->handOver($this->meridian, $this->reloaded($asset), [
            'custodian_id' => $this->siteManager->getKey(),
            'org_unit_id' => $this->construction->id,
        ], $this->fleetManager);

        $this->assertTrue($this->reloaded($asset)->custodyMatchesTrail());

        /*
         * AND THE OTHER HALF, WHICH IS THE ONE THAT MATTERS.
         *
         * The assertion above passes on a system where `custodyMatchesTrail()` returns true
         * unconditionally, so it is worth nothing on its own. Here the cache is corrupted the way a
         * bad migration or a stray update would corrupt it — the trail untouched — and the method
         * has to notice. If it does not, the register can disagree with its own history and say so
         * to nobody.
         */
        $this->withinOrganization($this->meridian, fn () => Asset::query()
            ->whereKey($asset->getKey())
            ->update(['custodian_id' => $this->fleetManager->getKey()]));

        $this->assertFalse($this->reloaded($asset)->custodyMatchesTrail());
    }

    /**
     * The asset again, read the way the application reads it.
     *
     * NOT `refresh()`, and the difference is the whole tenancy design working. `refresh()` reloads
     * whatever relations were already loaded, and `assets.status` is one of them — but a test method
     * body runs OUTSIDE any organization, where OrganizationScope fails closed, so the status comes
     * back null and every guard written as "only an asset in use may be handed over" then refuses
     * for the wrong reason. Reading inside the tenant is not a test convenience; it is what a
     * request does.
     *
     * The trail is loaded here too because `custodyMatchesTrail()` reads the collection rather than
     * issuing a query, and lazy loading is prevented outside production.
     */
    private function reloaded(Asset $asset): Asset
    {
        /** @var Asset */
        return $this->withinOrganization(
            $this->meridian,
            fn () => Asset::query()->with(['status', 'custodyTransfers'])->findOrFail($asset->getKey())
        );
    }

    /** @return Collection<int,AssetCustodyTransfer> */
    private function trailOf(Asset $asset): Collection
    {
        return $this->reloaded($asset)->custodyTransfers;
    }
}
