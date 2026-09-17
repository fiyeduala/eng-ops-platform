<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\GoodsReceiptItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsAssets;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class ReceiptAssetTest extends TestCase
{
    use BuildsAssets, BuildsCommercial, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Delivery assets');
        $this->manager = $this->makePerson($this->org, 'Receiver', null, [
            $this->makeRole($this->org, 'Asset registrar', [
                ...$this->fleetGrants(),
                'goods_receipt.view' => 'organization',
                'purchase_order.view' => 'organization',
                'vendor.view' => 'organization',
            ]),
        ]);
    }

    private function delivery(bool $accepted = true, int $quantity = 1, string $currency = 'USD', ?string $rate = '1'): GoodsReceiptItem
    {
        $buyer = $this->makePerson($this->org, 'Buyer');
        $vendor = $this->makeVendor($this->org, $buyer, 'Plant supplier');

        return $this->withinOrganization($this->org, function () use ($buyer, $vendor, $accepted, $quantity, $currency, $rate) {
            $orders = app(PurchaseOrderService::class);
            $order = $orders->create([
                'title' => 'Generator purchase', 'vendor_id' => $vendor->id,
                'currency' => $currency, 'exchange_rate' => $rate,
                'items' => [['description' => 'New generator', 'quantity' => $quantity, 'uom' => 'each', 'unit_price' => 125000]],
            ], $buyer);
            $orders->submit($order, $buyer);
            $orders->approve($order, $this->manager, 'Approved plant replacement');
            $orders->issue($order, $buyer);
            $receipts = app(GoodsReceiptService::class);
            $receipt = $receipts->create([
                'purchase_order_id' => $order->id,
                'items' => [['purchase_order_item_id' => $order->items()->firstOrFail()->id, 'quantity_accepted' => $quantity]],
            ], $this->manager);
            if ($accepted) {
                $receipts->confirm($receipt, $this->manager);
                $receipts->accept($receipt, $this->manager);
            }

            return $receipt->items()->firstOrFail();
        });
    }

    private function payload(string $code = 'NEW-GEN'): array
    {
        return ['code' => $code, 'name' => 'New generator', 'confidentiality' => 'internal'];
    }

    #[Test]
    public function accepted_delivery_can_be_registered_once_with_source_details_preserved(): void
    {
        $line = $this->delivery();
        $this->actingFresh($this->manager)->get(route('assets.from-receipt.create', $line))->assertOk()->assertSee('New generator');
        $this->actingFresh($this->manager)->post(route('assets.from-receipt.store', $line), [
            ...$this->payload(), 'purchase_cost' => 1, 'acquired_on' => '2000-01-01',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $asset = $this->withinOrganization($this->org, function () use ($line) {
            $asset = Asset::where('goods_receipt_item_id', $line->id)->firstOrFail();
            $this->assertSame('125000.00', $asset->purchase_cost);
            $this->assertSame($line->purchaseOrderItem->purchaseOrder->vendor_id, $asset->vendor_id);
            $this->assertTrue($asset->acquired_on->isToday());

            return $asset;
        });
        $this->actingFresh($this->manager)->get(route('goods-receipts.show', $line->goods_receipt_id))
            ->assertOk()->assertSee('Asset '.$asset->code)->assertDontSee('Register as an asset');
        $this->actingFresh($this->manager)->post(route('assets.from-receipt.store', $line), $this->payload('DUPLICATE'))
            ->assertSessionHasErrors('code');
        $this->assertDatabaseCount('assets', 1);
    }

    #[Test]
    public function foreign_purchase_cost_uses_the_recorded_rate_and_refuses_a_missing_rate(): void
    {
        $line = $this->delivery(currency: 'GBP', rate: '1.25555');
        $this->actingFresh($this->manager)->post(route('assets.from-receipt.store', $line), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('assets', ['goods_receipt_item_id' => $line->id, 'purchase_cost' => '156943.75']);
        // Acceptance now posts the finance cost atomically and therefore refuses a foreign order
        // with no fixed rate. Clear it after acceptance to keep this test focused on the asset
        // registrar's own defence against an unusable purchase price.
        $withoutRate = $this->delivery(currency: 'GBP', rate: '1');
        $this->withinOrganization($this->org, function () use ($withoutRate) {
            $order = $withoutRate->purchaseOrderItem->purchaseOrder;
            $order->forceFill(['exchange_rate' => null])->save();
        });
        $this->actingFresh($this->manager)->post(route('assets.from-receipt.store', $withoutRate), $this->payload('NO-RATE'))
            ->assertSessionHasErrors('code');
        $this->assertDatabaseCount('assets', 1);
    }

    #[Test]
    public function draft_receipts_and_bulk_lines_cannot_be_registered_as_one_asset(): void
    {
        foreach ([$this->delivery(false), $this->delivery(true, 2)] as $line) {
            $this->actingFresh($this->manager)->post(route('assets.from-receipt.store', $line), $this->payload())
                ->assertSessionHasErrors('code');
        }
        $this->assertDatabaseCount('assets', 0);
    }

    #[Test]
    public function asset_creation_permission_alone_cannot_read_or_copy_a_purchase_record(): void
    {
        $line = $this->delivery();
        $outsider = $this->makePerson($this->org, 'Registrar without purchasing access', null, [
            $this->makeRole($this->org, 'Asset only', ['asset.create' => 'organization']),
        ]);
        $this->actingFresh($outsider)->get(route('assets.from-receipt.create', $line))->assertForbidden();
        $this->actingFresh($outsider)->post(route('assets.from-receipt.store', $line), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('assets', 0);
    }

    #[Test]
    public function receipt_registration_cannot_cross_organizations(): void
    {
        $line = $this->delivery();
        $other = $this->makeOrganization('Other company');
        $user = $this->makePerson($other, 'Other registrar', null, [
            $this->makeRole($other, 'Registrar', ['asset.create' => 'organization']),
        ]);
        $this->actingFresh($user)->get(route('assets.from-receipt.create', $line))->assertNotFound();
        $this->actingFresh($user)->post(route('assets.from-receipt.store', $line), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('assets', 0);
    }
}
