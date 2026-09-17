<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\RfqService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsProcurement;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The join ProcurementChainTest said would be asserted here: a delivery becomes stock.
 *
 * ProcurementChainTest proves the paper trail — requisition, enquiry, award, order, receipt — and
 * stops at the gate. This file carries it over the threshold and onto a shelf, which is the half of
 * §90 that is easy to claim and hard to have: a goods receipt that changes a balance, with a ledger
 * row that names the receipt line it came from and a unit cost that is the price the company is
 * going to be invoiced.
 *
 * TWO LINKS MAKE IT WORK AND EITHER ONE MISSING IS SILENT.
 *
 *   `purchase_request_items.stock_item_id` says WHAT was bought in the catalogue's terms, and it
 *   travels requisition → enquiry → quotation → order → receipt without anybody retyping it.
 *
 *   `goods_receipts.warehouse_id` says WHERE it went.
 *
 * A receipt missing either is still a complete and correct record of a delivery — it simply never
 * reaches the ledger, which is the right answer for the pallet that went straight to site. So the
 * tests below assert both directions: that the chain posts when the links are there, and that it
 * posts NOTHING, quietly and without failing the acceptance, when they are not.
 *
 * WHO POSTS IT. Nobody, in the sense that matters: the ledger row is written by ACCEPTANCE, which
 * is `goods_receipt.approve`, and the person accepting is not required to hold `stock.create`. The
 * act being authorized is agreeing to keep the goods; putting them on the shelf is the consequence,
 * and making it a second permission would mean a manager could accept a delivery the system then
 * refused to record.
 */
final class GoodsReceiptToStockTest extends TestCase
{
    use BuildsCommercial, BuildsInventory, BuildsProcurement, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private OrgUnit $unit;

    private Project $project;

    private Warehouse $store;

    private StockItem $pipe;

    /** Raises the requisition. */
    private User $tunde;

    /** Buys, and raises the order. */
    private User $adaeze;

    /** Approves the money, and accepts the delivery. */
    private User $folake;

    /** Counts what came off the lorry. */
    private User $segun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->unit = $this->makeUnit($this->meridian, 'Projects', OrgUnitType::Division);

        $this->tunde = $this->makePerson($this->meridian, 'Tunde Bello', $this->unit, [
            $this->makeRole($this->meridian, 'Site Engineer', $this->grants(['purchase_request'])),
        ]);

        $this->adaeze = $this->makePerson($this->meridian, 'Adaeze Nwachukwu', $this->unit, [
            $this->makeRole($this->meridian, 'Buyer', $this->grants(
                ['purchase_request', 'rfq', 'quotation', 'purchase_order', 'goods_receipt', 'vendor'],
                ['rfq.issue', 'quotation.compare', 'purchase_order.submit', 'purchase_request.submit'],
            )),
        ]);

        $this->folake = $this->makePerson($this->meridian, 'Folake Adeyemi', $this->unit, [
            $this->makeRole($this->meridian, 'Procurement Manager', $this->grants(
                ['purchase_request', 'purchase_order', 'goods_receipt', 'quotation', 'rfq'],
                [
                    'purchase_request.approve', 'purchase_order.approve', 'purchase_order.issue',
                    'goods_receipt.approve',
                ],
            )),
        ]);

        $this->segun = $this->makePerson($this->meridian, 'Segun Aluko', $this->unit, [
            $this->makeRole($this->meridian, 'Storekeeper', $this->grants(
                ['goods_receipt', 'warehouse', 'stock_item'],
                //  on both because opening a store and activating a catalogue item are
                // status moves, and a storekeeper who cannot open the store cannot use it.
                ['purchase_order.view', 'stock.view', 'stock.create', 'warehouse.manage', 'warehouse.suspend', 'stock_item.manage'],
            )),
        ]);

        $this->project = $this->makeProject($this->meridian, $this->tunde, 'Ikeja Tie-in Package');

        // The store and the catalogue entry, both opened the way the application opens them: a
        // warehouse in `draft` and an item in `draft` refuse every movement, and a fixture that
        // inserted them ready-to-use would prove nothing about the two graphs that say so.
        $this->store = $this->makeWarehouse($this->meridian, $this->segun, 'WH-MAIN', [
            'name' => 'Ikeja Main Store',
        ]);

        $this->pipe = $this->makeStockItem($this->meridian, $this->segun, 'MAT-PIPE-12', [
            'name' => '12" carbon steel pipe, schedule 40',
            'uom' => 'm',
        ]);
    }

    #[Test]
    public function accepting_a_delivery_puts_it_on_the_shelf(): void
    {
        $order = $this->orderReadyForDelivery();

        // The store is named on the receipt. That single field is the difference between a delivery
        // note and a stock movement.
        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, null, [
            'warehouse_id' => $this->store->getKey(),
            'delivery_note_ref' => 'DN-44119',
        ]);

        $this->inTenant(function () use ($receipt) {
            $service = app(GoodsReceiptService::class);
            $service->confirm($receipt, $this->segun);

            // NOTHING ON THE SHELF YET. Confirming says the count is right; the company has not
            // said it is keeping them. Stock that has not been agreed to is not stock.
            $this->assertSame(0, StockTransaction::query()->count());
            $this->assertNull($this->balanceOf($this->store, $this->pipe));

            $service->accept($receipt, $this->folake, 'Certificates present and correct.');
        });

        $movement = $this->inTenant(fn () => StockTransaction::query()->sole());

        $this->assertSame('receipt', $movement->type);
        $this->assertSame(24.0, (float) $movement->quantity);
        $this->assertSame($this->store->getKey(), $movement->warehouse_id);
        $this->assertSame($this->pipe->getKey(), $movement->stock_item_id);

        // THE COST IS THE ORDER'S PRICE, not the requisition's guess of 45,000. What the company
        // will be invoiced is what the stock is worth, and a valuation built on an estimate would
        // drift away from the ledger the moment anybody negotiated.
        $this->assertSame(41000.0, (float) $movement->unit_cost);

        // It names the line it came from, so the balance can be walked back to a delivery note and
        // from there to an order and a requisition (§90).
        $this->assertSame('goods_receipt_item', $movement->source_type);
        $this->assertSame(
            $this->inTenant(fn () => $receipt->items()->value('id')),
            (int) $movement->source_id,
        );

        // And the project it was bought for travels with it, which is what makes the cost of the
        // job answerable from the store rather than from somebody's memory.
        $this->assertSame($this->project->getKey(), $movement->project_id);

        $balance = $this->balanceOf($this->store, $this->pipe);

        $this->assertSame(24.0, (float) $balance->quantity_on_hand);
        $this->assertSame(41000.0, (float) $balance->average_cost);
    }

    #[Test]
    public function foreign_order_prices_are_converted_before_stock_valuation(): void
    {
        $order = $this->orderReadyForDelivery();
        $this->inTenant(function () use ($order) {
            $order->forceFill(['currency' => 'GBP', 'exchange_rate' => '1.25'])->save();
        });
        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, null, [
            'warehouse_id' => $this->store->getKey(),
        ]);

        $this->inTenant(function () use ($receipt) {
            $service = app(GoodsReceiptService::class);
            $service->confirm($receipt, $this->segun);
            $service->accept($receipt, $this->folake);
        });

        $movement = $this->inTenant(fn () => StockTransaction::query()->sole());
        $this->assertSame(51250.0, (float) $movement->unit_cost);
        $this->assertSame(51250.0, (float) $this->balanceOf($this->store, $this->pipe)->average_cost);
        $this->assertDatabaseHas('project_costs', [
            'currency' => 'GBP',
            'amount' => '984000.00',
            'base_currency' => 'USD',
            'base_amount' => '1230000.00',
        ]);
    }

    #[Test]
    public function a_delivery_with_no_store_named_reaches_no_shelf(): void
    {
        $order = $this->orderReadyForDelivery();

        // Straight to site. The pipe is going into the ground this week and never sees a rack.
        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order);

        $this->inTenant(function () use ($receipt) {
            $service = app(GoodsReceiptService::class);
            $service->confirm($receipt, $this->segun);
            $service->accept($receipt, $this->folake);
        });

        // Accepted, and correctly so — the delivery happened and the order is satisfied.
        $this->assertSame('accepted', $this->statusOf($receipt));
        $this->assertSame('received', $this->statusOf($order->refresh()));

        // And nothing on any shelf, because nobody said there was a shelf. This is the case the
        // whole design turns on: no error, no warning, no invented warehouse.
        $this->assertSame(0, $this->inTenant(fn () => StockTransaction::query()->count()));
    }

    #[Test]
    public function a_line_that_names_no_catalogue_item_reaches_no_shelf(): void
    {
        // A one-off: a machined bush to a drawing. It will never be a catalogue item and it should
        // never need to be.
        $order = $this->orderReadyForDelivery(catalogued: false);

        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, null, [
            'warehouse_id' => $this->store->getKey(),
        ]);

        $this->inTenant(function () use ($receipt) {
            $service = app(GoodsReceiptService::class);
            $service->confirm($receipt, $this->segun);
            $service->accept($receipt, $this->folake);
        });

        // The store was named and it still posts nothing, because there is no balance to move: you
        // cannot hold a quantity of a description.
        $this->assertSame(0, $this->inTenant(fn () => StockTransaction::query()->count()));
        $this->assertSame('accepted', $this->statusOf($receipt));
    }

    #[Test]
    public function a_refused_quantity_never_reaches_the_shelf(): void
    {
        $order = $this->orderReadyForDelivery();
        $line = $this->inTenant(fn () => $order->items()->first());

        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, [
            [
                'purchase_order_item_id' => $line->id,
                'quantity_accepted' => 18,
                'quantity_rejected' => 6,
                'rejection_reason' => 'Schedule 20 pipe against a schedule 40 order.',
                'condition' => 'Wrong item',
            ],
        ], [
            'warehouse_id' => $this->store->getKey(),
        ]);

        $this->inTenant(function () use ($receipt) {
            $service = app(GoodsReceiptService::class);
            $service->confirm($receipt, $this->segun);
            $service->accept($receipt, $this->folake);
        });

        // Eighteen, not twenty-four. The six that went back on the lorry were delivered and were
        // refused, which are two facts and neither of them is "in stock".
        $this->assertSame(18.0, (float) $this->balanceOf($this->store, $this->pipe)->quantity_on_hand);
    }

    #[Test]
    public function two_part_deliveries_post_two_movements_and_one_balance(): void
    {
        $order = $this->orderReadyForDelivery();
        $line = $this->inTenant(fn () => $order->items()->first());

        foreach ([10, 14] as $quantity) {
            $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, [
                ['purchase_order_item_id' => $line->id, 'quantity_accepted' => $quantity, 'condition' => 'Good'],
            ], [
                'warehouse_id' => $this->store->getKey(),
            ]);

            $this->inTenant(function () use ($receipt) {
                $service = app(GoodsReceiptService::class);
                $service->confirm($receipt, $this->segun);
                $service->accept($receipt, $this->folake);
            });
        }

        // Two lorries, two rows, one shelf. The ledger records the events; the balance records the
        // position, and asking either one the same question has to give the same answer.
        $this->assertSame(2, $this->inTenant(fn () => StockTransaction::query()->count()));
        $this->assertSame(24.0, (float) $this->balanceOf($this->store, $this->pipe)->quantity_on_hand);
    }

    #[Test]
    public function another_tenants_store_cannot_be_named_on_a_receipt(): void
    {
        // Kestrel's store, and an id Meridian's storekeeper has no business finding. The lookup is
        // tenant-scoped, so it is not refused — it is simply not there (§75, §84, §94).
        $kestrel = $this->makeOrganization('Kestrel Energy');
        $kestrelUnit = $this->makeUnit($kestrel, 'Facilities', OrgUnitType::Division);
        $chidi = $this->makePerson($kestrel, 'Chidi Okonkwo', $kestrelUnit, [
            $this->makeRole($kestrel, 'Storekeeper', $this->grants(
                ['warehouse', 'stock_item'],
                ['warehouse.manage', 'warehouse.suspend', 'stock_item.manage'],
            )),
        ]);
        $foreign = $this->makeWarehouse($kestrel, $chidi, 'KES-WH', ['name' => 'Kestrel Yard']);

        $order = $this->orderReadyForDelivery();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('That store could not be found.');

        $this->makeGoodsReceipt($this->meridian, $this->segun, $order, null, [
            'warehouse_id' => $foreign->getKey(),
        ]);
    }

    #[Test]
    public function a_suspended_store_is_refused_while_the_receipt_is_being_written(): void
    {
        $order = $this->orderReadyForDelivery();

        $this->inTenant(fn () => $this->moveWarehouseTo($this->store, 'suspended', $this->segun));

        // Refused NOW, at the moment the storekeeper chooses it, rather than at acceptance — where
        // it would roll back the whole acceptance transaction days later and leave a manager
        // looking at an error about a store they never touched.
        $this->expectException(\DomainException::class);

        $this->makeGoodsReceipt($this->meridian, $this->segun, $order, null, [
            'warehouse_id' => $this->store->getKey(),
        ]);
    }

    #[Test]
    public function the_catalogue_link_survives_the_whole_chain_without_being_retyped(): void
    {
        $order = $this->orderReadyForDelivery();

        // Nobody typed the catalogue item after the requisition. It was carried by the enquiry, by
        // the quotation and by the order, and this is the assertion that says so: the order line
        // resolves back to the same catalogue row the engineer picked.
        $this->inTenant(function () use ($order) {
            $item = $order->items()->with('requestItem.stockItem')->first();

            $this->assertSame($this->pipe->getKey(), $item->requestItem?->stockItem?->getKey());
        });
    }

    // ================================================================== fixtures

    /**
     * An issued order for 24 m of pipe, with the catalogue link in place unless told otherwise.
     *
     * The whole procurement chain is run rather than shortcut, because the thing under test is
     * whether `stock_item_id` survives four hops without anybody retyping it. An order assembled by
     * hand would carry the link because the fixture put it there, which proves nothing.
     */
    private function orderReadyForDelivery(bool $catalogued = true): PurchaseOrder
    {
        $delta = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');

        $request = $this->makeApprovedPurchaseRequest(
            $this->meridian,
            $this->tunde,
            $this->folake,
            'Tie-in spool materials',
            [
                'project_id' => $this->project->getKey(),
                'items' => [
                    [
                        // The one field this whole file is about.
                        'stock_item_id' => $catalogued ? $this->pipe->getKey() : null,
                        'description' => $catalogued
                            ? '12" carbon steel pipe, schedule 40'
                            : 'Machined bush to drawing 4471-B',
                        'quantity' => 24,
                        'uom' => 'm',
                        'estimated_unit_price' => 45000,
                    ],
                ],
            ],
        );

        $rfq = $this->makeRfq($this->meridian, $this->adaeze, $request, [$delta]);

        $this->inTenant(fn () => app(RfqService::class)->issue($rfq, $this->adaeze));

        $quotation = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $delta, 41000);

        $this->inTenant(fn () => app(RfqService::class)
            ->award($rfq, $quotation, $this->adaeze, 'Only compliant offer.'));

        $order = $this->makePurchaseOrder($this->meridian, $this->adaeze, $quotation, [
            'project_id' => $this->project->getKey(),
        ]);

        $this->inTenant(function () use ($order) {
            $service = app(PurchaseOrderService::class);
            $service->submit($order, $this->adaeze);
            $service->approve($order, $this->folake);
            $service->issue($order, $this->folake);
        });

        return $this->inTenant(fn () => $order->refresh());
    }

    /**
     * NOT A CONVENIENCE. OrganizationScope fails closed, so a read taken outside a tenant context
     * returns nothing rather than failing — and an assertion built on it passes for the wrong
     * reason. Every scoped read in this file goes through here or through balanceOf().
     */
    private function inTenant(callable $work): mixed
    {
        return $this->withinOrganization($this->meridian, $work);
    }

    private function statusOf(Model $record): ?string
    {
        return $this->inTenant(fn () => $record->refresh()->statusKey());
    }

    /**
     * @param  array<int,string>  $modules  every CRUD ability on each
     * @param  array<int,string>  $extra  named abilities on top
     * @return array<string,string>
     */
    private function grants(array $modules, array $extra = []): array
    {
        $grants = [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ];

        foreach ($modules as $module) {
            foreach (['view', 'create', 'edit'] as $ability) {
                $grants[$module.'.'.$ability] = 'organization';
            }
        }

        foreach ($extra as $key) {
            $grants[$key] = 'organization';
        }

        return $grants;
    }
}
