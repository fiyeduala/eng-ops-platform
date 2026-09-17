<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class ProjectCostTest extends TestCase
{
    use BuildsCommercial, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $financeManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Cost ledger company');
        $this->financeManager = $this->makePerson($this->org, 'Finance Manager', null, [
            $this->makeRole($this->org, 'Cost reader', [
                'expense.view' => 'organization',
                'project.view' => 'organization',
                'goods_receipt.view' => 'organization',
                'purchase_order.view' => 'organization',
            ]),
        ]);
    }

    #[Test]
    public function accepting_a_delivery_posts_an_attributed_cost_with_exact_currency_values(): void
    {
        [$receipt, $project] = $this->delivery(
            currency: 'GBP',
            rate: '1.25555',
            quantityAccepted: '3',
            quantityRejected: '1',
            unitPrice: '19.995',
            withProject: true,
        );

        $cost = $this->withinOrganization($this->org, fn () => ProjectCost::firstOrFail());

        $this->assertSame('procurement', $cost->cost_type);
        $this->assertSame('3.0000', $cost->quantity);
        $this->assertSame('19.9950', $cost->unit_price);
        $this->assertSame('GBP', $cost->currency);
        $this->assertSame('59.99', $cost->amount);
        $this->assertSame('USD', $cost->base_currency);
        $this->assertSame('75.32', $cost->base_amount);
        $this->assertSame($project->id, $cost->project_id);
        $this->assertSame($receipt->purchaseOrder->vendor_id, $cost->vendor_id);
        $lineId = $this->withinOrganization($this->org, fn () => $receipt->items()->firstOrFail()->id);
        $this->assertSame($lineId, $cost->source_id);

        $this->actingFresh($this->financeManager)->get(route('project-costs.index'))
            ->assertOk()->assertSee($cost->number)->assertSee('USD 75.32');
        $this->actingFresh($this->financeManager)->get(route('project-costs.show', $cost))
            ->assertOk()->assertSee('GBP 59.99')->assertSee($project->name);
        $this->actingFresh($this->financeManager)->get(route('goods-receipts.show', $receipt))
            ->assertOk()->assertSee('Cost '.$cost->number);
    }

    #[Test]
    public function direct_to_site_and_uncatalogued_deliveries_still_become_project_costs(): void
    {
        [$receipt] = $this->delivery(quantityAccepted: '2.5', unitPrice: '80');

        $this->assertNull($receipt->warehouse_id);
        $this->assertDatabaseCount('stock_transactions', 0);
        $lineId = $this->withinOrganization($this->org, fn () => $receipt->items()->firstOrFail()->id);
        $this->assertDatabaseHas('project_costs', [
            'source_id' => $lineId,
            'amount' => '200.00',
            'base_amount' => '200.00',
        ]);
    }

    #[Test]
    public function accepting_the_same_receipt_again_does_not_duplicate_its_cost(): void
    {
        [$receipt] = $this->delivery();

        $this->withinOrganization($this->org, fn () => app(GoodsReceiptService::class)
            ->accept($receipt->fresh(), $this->financeManager));

        $this->assertDatabaseCount('project_costs', 1);
    }

    #[Test]
    public function a_missing_foreign_exchange_rate_rolls_back_the_whole_acceptance(): void
    {
        [$receipt] = $this->delivery(currency: 'GBP', rate: null, accept: false);

        try {
            $this->withinOrganization($this->org, fn () => app(GoodsReceiptService::class)
                ->accept($receipt->fresh(), $this->financeManager));
            $this->fail('Acceptance should require the fixed purchase-order exchange rate.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('exchange rate', $exception->getMessage());
        }

        $this->assertDatabaseCount('project_costs', 0);
        $this->assertSame('received', $this->withinOrganization(
            $this->org,
            fn () => $receipt->fresh()->statusKey(),
        ));
    }

    #[Test]
    public function finance_permissions_and_tenant_scope_protect_the_ledger(): void
    {
        $this->delivery();
        $cost = $this->withinOrganization($this->org, fn () => ProjectCost::firstOrFail());
        $withoutFinance = $this->makePerson($this->org, 'Operations Reader');

        $this->actingFresh($withoutFinance)->get(route('project-costs.index'))->assertForbidden();
        $this->actingFresh($withoutFinance)->get(route('project-costs.show', $cost))->assertForbidden();

        $other = $this->makeOrganization('Other company');
        $otherManager = $this->makePerson($other, 'Other Finance Manager', null, [
            $this->makeRole($other, 'Other cost reader', ['expense.view' => 'organization']),
        ]);
        $this->actingFresh($otherManager)->get(route('project-costs.show', $cost))->assertNotFound();
    }

    /** @return array{GoodsReceipt, Project|null} */
    private function delivery(
        string $currency = 'USD',
        ?string $rate = '1',
        string $quantityAccepted = '1',
        string $quantityRejected = '0',
        string $unitPrice = '125',
        bool $withProject = false,
        bool $accept = true,
    ): array {
        $buyer = $this->makePerson($this->org, 'Buyer '.fake()->unique()->firstName());
        $vendor = $this->makeVendor($this->org, $buyer, 'Supplier '.fake()->unique()->company());
        $project = $withProject ? $this->makeProject($this->org, $buyer, 'Plant upgrade') : null;

        return $this->withinOrganization($this->org, function () use (
            $buyer, $vendor, $project, $currency, $rate, $quantityAccepted,
            $quantityRejected, $unitPrice, $accept,
        ) {
            $orders = app(PurchaseOrderService::class);
            $order = $orders->create([
                'title' => 'Site materials',
                'vendor_id' => $vendor->id,
                'project_id' => $project?->id,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'items' => [[
                    'description' => 'Fabricated support',
                    'quantity' => (float) $quantityAccepted + (float) $quantityRejected,
                    'uom' => 'each',
                    'unit_price' => $unitPrice,
                ]],
            ], $buyer);
            $orders->submit($order, $buyer);
            $orders->approve($order, $this->financeManager, 'Approved for the works');
            $orders->issue($order, $buyer);

            $receipts = app(GoodsReceiptService::class);
            $receipt = $receipts->create([
                'purchase_order_id' => $order->id,
                'items' => [[
                    'purchase_order_item_id' => $order->items()->firstOrFail()->id,
                    'quantity_accepted' => $quantityAccepted,
                    'quantity_rejected' => $quantityRejected,
                    'rejection_reason' => (float) $quantityRejected > 0 ? 'Damaged in transit' : null,
                ]],
            ], $this->financeManager);
            $receipts->confirm($receipt, $this->financeManager);

            if ($accept) {
                $receipts->accept($receipt, $this->financeManager);
            }

            return [$receipt->refresh(), $project];
        });
    }
}
