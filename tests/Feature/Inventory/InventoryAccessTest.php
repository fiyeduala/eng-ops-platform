<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Organization;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Enums\OrgUnitType;
use App\Support\RoleTemplates;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Who may see the store, and who may not (§75, §84, §94).
 *
 * TWO COMPANIES KEEP STOCK IN ONE DATABASE. Meridian's storekeeper and Kestrel's storekeeper both
 * hold `stock.*` at organization scope, which is exactly the shape of grant that makes a tenant
 * leak invisible: neither of them is doing anything unusual, and a missing scope on one query would
 * show each of them the other's shelves without a single permission being wrong.
 *
 * THE STATUS CONVENTION IS ASSERTED, NOT ASSUMED. A record in another tenant answers 404, because
 * 403 would confirm that the id exists; a record in this tenant that the reader may not have
 * answers 403, because pretending it is not there would be a lie to somebody who can see it named
 * on another page.
 *
 * AND VALUE IS ITS OWN QUESTION. `stock.view_value` is not `stock.view`: a storekeeper who counts
 * and issues all day has no business knowing what the shelf is worth unless somebody said so.
 */
final class InventoryAccessTest extends TestCase
{
    use BuildsInventory, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private User $musa;

    private User $chidi;

    private Warehouse $meridianStore;

    private StockItem $meridianPipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->musa = $this->makePerson(
            $this->meridian,
            'Musa Danladi',
            $this->makeUnit($this->meridian, 'Stores', OrgUnitType::Department),
            [$this->makeRole($this->meridian, 'Store Manager', $this->storeGrants())],
        );

        $this->kestrel = $this->makeOrganization('Kestrel Energy');
        $this->chidi = $this->makePerson(
            $this->kestrel,
            'Chidi Okonkwo',
            $this->makeUnit($this->kestrel, 'Stores', OrgUnitType::Department),
            [$this->makeRole($this->kestrel, 'Store Manager', $this->storeGrants())],
        );

        $this->meridianStore = $this->makeWarehouse($this->meridian, $this->musa);
        $this->meridianPipe = $this->makeStockItem($this->meridian, $this->musa);
    }

    #[Test]
    public function the_keeper_of_one_company_cannot_open_another_companys_store(): void
    {
        // Chidi holds `warehouse.view` at organization scope. The organization is not this one.
        $this->actingFresh($this->chidi)
            ->get(route('warehouses.show', $this->meridianStore))
            ->assertNotFound();

        $this->actingFresh($this->musa)
            ->get(route('warehouses.show', $this->meridianStore))
            ->assertOk();
    }

    #[Test]
    public function walking_the_url_from_one_movement_to_another_does_not_cross_the_wall(): void
    {
        $this->openingStock($this->meridian, $this->meridianStore, $this->meridianPipe, 100, $this->musa);

        $movement = $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->firstOrFail()
        );

        // The literal §94 test, on the register where it matters most: a ledger row names a
        // quantity, a price and a project, and the id in the URL is the only thing between them.
        $this->actingFresh($this->chidi)
            ->get(route('stock.show', $movement))
            ->assertNotFound();
    }

    #[Test]
    public function another_companys_catalogue_line_is_not_reachable_either(): void
    {
        $this->actingFresh($this->chidi)
            ->get(route('stock-items.show', $this->meridianPipe))
            ->assertNotFound();
    }

    #[Test]
    public function the_ledger_index_shows_only_this_tenants_movements(): void
    {
        $this->openingStock($this->meridian, $this->meridianStore, $this->meridianPipe, 100, $this->musa);

        $kestrelStore = $this->makeWarehouse($this->kestrel, $this->chidi, 'WH-PH', ['name' => 'Port Harcourt Store']);
        $kestrelValve = $this->makeStockItem($this->kestrel, $this->chidi, 'MAT-9001', [
            'name' => 'Gate valve, 6 inch, class 300',
            'uom' => 'ea',
        ]);
        $this->openingStock($this->kestrel, $kestrelStore, $kestrelValve, 12, $this->chidi);

        // A listing obeys the same rules as a page (§61). Musa sees his pipe and not Kestrel's
        // valves, and the assertion is on the item NAME rather than a count, because a count that
        // happens to match is a test that passes while the wall is down.
        $this->actingFresh($this->musa)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('carbon steel pipe', false)
            ->assertDontSee('Gate valve', false);

        $this->actingFresh($this->chidi)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Gate valve', false)
            ->assertDontSee('carbon steel pipe', false);
    }

    #[Test]
    public function the_material_request_register_shows_only_this_tenants_requests(): void
    {
        $kestrelStore = $this->makeWarehouse($this->kestrel, $this->chidi, 'WH-PH', ['name' => 'Port Harcourt Store']);
        $kestrelValve = $this->makeStockItem($this->kestrel, $this->chidi, 'MAT-9001', [
            'name' => 'Gate valve, 6 inch, class 300',
            'uom' => 'ea',
        ]);

        $this->makeMaterialRequest($this->meridian, $this->musa, $this->meridianPipe, $this->meridianStore, null, [
            'title' => 'Pipe for the eastern tie-in',
        ]);

        $this->makeMaterialRequest($this->kestrel, $this->chidi, $kestrelValve, $kestrelStore, null, [
            'title' => 'Valves for the wellhead skid',
        ]);

        /*
         * THE ONE REGISTER INDEX NOTHING ELSE OPENS.
         *
         * `stock.index` has had this assertion since the ledger was written, and the material
         * request register — the page a storekeeper actually lives on — had nothing: forty-seven
         * inventory tests and not one of them issued a GET to it. The smoke test caught an unclosed
         * component tag here that no inventory test could have seen, which is the same coverage hole
         * viewed from the rendering side. A register nobody fetches is a register whose scope nobody
         * has checked.
         */
        $this->actingFresh($this->musa)
            ->get(route('material-requests.index'))
            ->assertOk()
            ->assertSee('Pipe for the eastern tie-in', false)
            ->assertDontSee('Valves for the wellhead skid', false);

        $this->actingFresh($this->chidi)
            ->get(route('material-requests.index'))
            ->assertOk()
            ->assertSee('Valves for the wellhead skid', false)
            ->assertDontSee('Pipe for the eastern tie-in', false);
    }

    #[Test]
    public function value_is_hidden_from_somebody_who_may_read_the_ledger_but_not_its_money(): void
    {
        $this->openingStock($this->meridian, $this->meridianStore, $this->meridianPipe, 100, $this->musa, 40000);

        $movement = $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->firstOrFail()
        );

        // Ada runs the counter. She may see every movement in the company and no money at all.
        $ada = $this->makePerson(
            $this->meridian,
            'Ada Eze',
            $this->makeUnit($this->meridian, 'Site Stores', OrgUnitType::Team),
            [$this->makeRole($this->meridian, 'Store Hand', [
                'stock.view' => 'organization',
                'warehouse.view' => 'organization',
                'stock_item.view' => 'organization',
                'org_unit.view' => 'organization',
            ])],
        );

        $this->actingFresh($ada)
            ->get(route('stock.show', $movement))
            ->assertOk()
            ->assertDontSee('40,000')
            ->assertDontSee('4,000,000');

        // And the same page, for somebody who holds the ability, does show it — otherwise the
        // assertion above would pass on a page that simply never renders a price.
        $this->actingFresh($this->musa)
            ->get(route('stock.show', $movement))
            ->assertOk()
            ->assertSee('40,000');
    }

    #[Test]
    public function there_is_no_way_to_edit_or_delete_a_movement_over_http(): void
    {
        $this->openingStock($this->meridian, $this->meridianStore, $this->meridianPipe, 100, $this->musa);

        $movement = $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->firstOrFail()
        );

        // The model refuses these outright ({@see \Tests\Feature\Inventory\StockLedgerTest}); this
        // asserts the other half, that no URL exists to ask. Musa holds `stock.*` — every ability
        // the module has — so a route that answered anything but 404/405 here would be a route
        // somebody had quietly added (§59).
        $this->actingFresh($this->musa)
            ->put('/stock/'.$movement->getKey(), ['quantity' => 999])
            ->assertStatus(405);

        $this->actingFresh($this->musa)
            ->delete('/stock/'.$movement->getKey())
            ->assertStatus(405);
    }

    #[Test]
    public function posting_a_movement_needs_the_ability_for_that_particular_verb(): void
    {
        $this->openingStock($this->meridian, $this->meridianStore, $this->meridianPipe, 100, $this->musa);

        // Ada may receive and issue. She may not count, and she may not reverse — the two acts that
        // change stock with no document behind them. One endpoint taking a `type` field would have
        // handed her both along with the first (§84).
        $ada = $this->makePerson(
            $this->meridian,
            'Ada Eze',
            $this->makeUnit($this->meridian, 'Site Stores', OrgUnitType::Team),
            [$this->makeRole($this->meridian, 'Store Hand', [
                'stock.view' => 'organization',
                'stock.create' => 'organization',
                'warehouse.view' => 'organization',
                'stock_item.view' => 'organization',
                'org_unit.view' => 'organization',
            ])],
        );

        $this->actingFresh($ada)->post(route('stock.count'), [
            'warehouse_id' => $this->meridianStore->getKey(),
            'stock_item_id' => $this->meridianPipe->getKey(),
            'counted_quantity' => 98,
            'reason' => 'Quarterly count',
        ])->assertForbidden();

        $movement = $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->firstOrFail()
        );

        $this->actingFresh($ada)->post(route('stock.reverse', $movement), [
            'reason' => 'Posted in error',
        ])->assertForbidden();
    }

    #[Test]
    public function a_movement_cannot_be_posted_into_another_companys_store(): void
    {
        $kestrelStore = $this->makeWarehouse($this->kestrel, $this->chidi, 'WH-PH', ['name' => 'Port Harcourt Store']);

        // Musa holds every stock ability there is. The ids in the form are the attack, not the
        // permissions — and the ids are resolved inside his own tenant, where this one is not (§84).
        $this->actingFresh($this->musa)->post(route('stock.receive'), [
            'warehouse_id' => $kestrelStore->getKey(),
            'stock_item_id' => $this->meridianPipe->getKey(),
            'quantity' => 10,
            'unit_cost' => 40000,
            'reason' => 'Reaching across the wall',
        ])->assertSessionHasErrors('warehouse_id');

        $this->assertSame(0, $this->withinOrganization(
            $this->kestrel,
            fn () => StockTransaction::query()->count()
        ));
    }

    #[Test]
    public function a_movement_cannot_be_posted_against_another_companys_catalogue_line(): void
    {
        $kestrelValve = $this->makeStockItem($this->kestrel, $this->chidi, 'MAT-9001', [
            'name' => 'Gate valve, 6 inch, class 300',
            'uom' => 'ea',
        ]);

        $this->actingFresh($this->musa)->post(route('stock.receive'), [
            'warehouse_id' => $this->meridianStore->getKey(),
            'stock_item_id' => $kestrelValve->getKey(),
            'quantity' => 10,
            'unit_cost' => 40000,
            'reason' => 'Reaching across the wall',
        ])->assertSessionHasErrors('stock_item_id');

        $this->assertSame(0, $this->withinOrganization(
            $this->meridian,
            fn () => StockTransaction::query()->count()
        ));
    }

    // ================================================================== fixtures

    /**
     * A store manager's grants, expanded the way the provisioner expands them.
     *
     * @return array<string,array<int,string>>
     */
    private function storeGrants(): array
    {
        return RoleTemplates::expand([
            'warehouse.*' => 'organization',
            'stock.*' => 'organization',
            'stock_item.*' => 'organization',
            'material_request.*' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);
    }
}
