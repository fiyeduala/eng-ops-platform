<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\RfqService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsProcurement;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * §90, proved: the five procurement registers are one chain, not five CRUD screens.
 *
 * The exit criterion for this phase is a sentence — "PR → approval → PO → goods receipt posts as one
 * connected chain" — and this file is that sentence executed. Every link is made the way the
 * application makes it, and the assertions are about what the OTHER records did: a requisition that
 * knows an order exists against it, an enquiry that knows it was awarded, a losing quotation that
 * knows it lost, an order whose status was moved by arithmetic over deliveries rather than by
 * anybody clicking it.
 *
 * The two halves of the chain that Phase 5 adds later — a stock transaction and a project cost —
 * are asserted in their own parts. What is proved here is that the paper trail holds end to end.
 */
final class ProcurementChainTest extends TestCase
{
    use BuildsCommercial, BuildsProcurement, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private OrgUnit $unit;

    private Project $project;

    /** Raises the requisition. */
    private User $tunde;

    /** Buys: runs the enquiry, awards it, raises the order. */
    private User $adaeze;

    /** Approves the money. */
    private User $folake;

    /** Receives the goods — deliberately nobody above. */
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
                ['rfq.issue', 'quotation.compare', 'quotation.view_confidential', 'purchase_order.submit', 'purchase_request.submit'],
            )),
        ]);

        $this->folake = $this->makePerson($this->meridian, 'Folake Adeyemi', $this->unit, [
            $this->makeRole($this->meridian, 'Procurement Manager', $this->grants(
                ['purchase_request', 'purchase_order', 'goods_receipt', 'quotation', 'rfq'],
                [
                    'purchase_request.approve', 'purchase_request.reject',
                    'purchase_order.approve', 'purchase_order.reject', 'purchase_order.issue',
                    'goods_receipt.approve',
                ],
            )),
        ]);

        $this->segun = $this->makePerson($this->meridian, 'Segun Aluko', $this->unit, [
            $this->makeRole($this->meridian, 'Storekeeper', $this->grants(
                ['goods_receipt'],
                ['purchase_order.view'],
            )),
        ]);

        $this->project = $this->makeProject($this->meridian, $this->tunde, 'Ikeja Tie-in Package');
    }

    #[Test]
    public function the_chain_runs_from_requisition_to_delivery(): void
    {
        $delta = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');
        $orion = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Orion Steel');

        // 1. A requisition, submitted by the engineer and approved by somebody else.
        $request = $this->makeApprovedPurchaseRequest(
            $this->meridian,
            $this->tunde,
            $this->folake,
            'Tie-in spool materials',
            ['project_id' => $this->project->id, 'org_unit_id' => $this->unit->id],
        );

        $this->assertSame('approved', $this->statusOf($request));

        // 2. An enquiry raised from it, carrying its lines across.
        $rfq = $this->makeRfq($this->meridian, $this->adaeze, $request, [$delta, $orion]);

        $this->assertSame($request->id, $rfq->purchase_request_id);
        $this->assertSame(
            $this->inTenant(fn () => $request->items()->count()),
            $this->inTenant(fn () => $rfq->items()->count()),
        );

        $this->inTenant(fn () => app(RfqService::class)->issue($rfq, $this->adaeze));

        // 3. Two quotations, and an award.
        $cheap = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $delta, 41000);
        $dear = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $orion, 47500);

        $this->inTenant(fn () => app(RfqService::class)
            ->award($rfq, $cheap, $this->adaeze, 'Lowest compliant offer.'));

        // The award is one act over the whole enquiry: the winner and the losers cannot disagree.
        $this->assertSame('awarded', $this->statusOf($rfq));
        $this->assertSame('accepted', $this->statusOf($cheap));
        $this->assertSame('unsuccessful', $this->statusOf($dear));

        // 4. The order, raised from the award rather than typed.
        $order = $this->makePurchaseOrder($this->meridian, $this->adaeze, $cheap, [
            'project_id' => $this->project->id,
        ]);

        $this->assertSame($cheap->id, $order->quotation_id);
        $this->assertSame($delta->id, $order->vendor_id);
        // The price on the order is the price that was awarded, not the requisition's estimate.
        $this->assertSame(
            '41000.0000',
            $this->inTenant(fn () => (string) $order->items()->first()->unit_price),
        );

        $this->inTenant(function () use ($order) {
            $service = app(PurchaseOrderService::class);
            $service->submit($order, $this->adaeze);
            $service->approve($order, $this->folake, 'Within the package budget.');
            $service->issue($order, $this->folake);
        });

        // Raising the order tells the requisition, which is how a requester learns their material
        // is coming without anybody sending them a message about it.
        $this->assertSame('ordered', $this->statusOf($request));
        $this->assertSame('issued', $this->statusOf($order));

        // 5. The delivery, counted by somebody who is neither the buyer nor an approver.
        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order);

        $this->assertSame('draft', $this->statusOf($receipt));

        $this->inTenant(fn () => app(GoodsReceiptService::class)->confirm($receipt, $this->segun));

        // The order's status is arithmetic over the receipts, not a button anybody pressed.
        $this->assertSame('received', $this->statusOf($order));
        $this->inTenant(function () use ($order) {
            $line = $order->items()->first();

            $this->assertSame((float) $line->quantity, (float) $line->acceptedQuantity());
        });
    }

    #[Test]
    public function a_short_delivery_leaves_the_order_partly_received(): void
    {
        $order = $this->orderReadyForDelivery();
        $line = $this->inTenant(fn () => $order->items()->first());

        // Ten of the twenty-four metres turned up.
        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, [
            ['purchase_order_item_id' => $line->id, 'quantity_accepted' => 10, 'condition' => 'Good'],
        ]);

        $this->inTenant(fn () => app(GoodsReceiptService::class)->confirm($receipt, $this->segun));

        $this->assertSame('part_received', $this->statusOf($order));
        $this->assertSame(14.0, $this->outstandingOn($order));

        // The rest, later. Two receipts against one order is the ordinary case, not an edge one.
        $second = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, [
            ['purchase_order_item_id' => $line->id, 'quantity_accepted' => 14, 'condition' => 'Good'],
        ]);

        $this->inTenant(fn () => app(GoodsReceiptService::class)->confirm($second, $this->segun));

        $this->assertSame('received', $this->statusOf($order));
        $this->assertSame(0.0, $this->outstandingOn($order));
    }

    #[Test]
    public function a_refused_quantity_does_not_count_towards_the_order(): void
    {
        $order = $this->orderReadyForDelivery();
        $line = $this->inTenant(fn () => $order->items()->first());

        // Twenty-four arrived; six of them were the wrong schedule and went back on the lorry.
        $receipt = $this->makeGoodsReceipt($this->meridian, $this->segun, $order, [
            [
                'purchase_order_item_id' => $line->id,
                'quantity_accepted' => 18,
                'quantity_rejected' => 6,
                'rejection_reason' => 'Schedule 20 pipe against a schedule 40 order.',
                'condition' => 'Wrong item',
            ],
        ]);

        $this->inTenant(fn () => app(GoodsReceiptService::class)->confirm($receipt, $this->segun));

        // Refused goods are recorded, and are not a delivery. The supplier still owes six metres.
        $this->assertSame('part_received', $this->statusOf($order));
        $this->assertSame(6.0, $this->outstandingOn($order));
        $this->assertTrue($this->inTenant(fn () => $receipt->refresh()->hasRejections()));
    }

    #[Test]
    public function the_person_who_raised_a_requisition_cannot_approve_it(): void
    {
        $request = $this->makePurchaseRequest($this->meridian, $this->tunde);

        $this->inTenant(fn () => app(PurchaseRequestService::class)->submit($request, $this->tunde));

        // Adaeze holds `purchase_request.approve` nowhere in this test; the point here is the other
        // control — Tunde raised it, so even with the grant the service refuses him (§84).
        $this->expectException(\DomainException::class);

        $this->inTenant(fn () => app(PurchaseRequestService::class)->approve($request, $this->tunde));
    }

    #[Test]
    public function the_buyer_cannot_receive_their_own_order(): void
    {
        $order = $this->orderReadyForDelivery();

        // Adaeze raised the order. Somebody else has to count what turns up against it, or the
        // company has one person who can both commit the money and certify it arrived.
        $this->expectException(\DomainException::class);

        $this->makeGoodsReceipt($this->meridian, $this->adaeze, $order);
    }

    // ================================================================== fixtures

    /**
     * Every read in this file goes through here, and that is the point.
     *
     * Statuses, line items and receipts are all tenant-scoped, and OrganizationScope FAILS CLOSED:
     * asked outside a tenant it returns nothing rather than everything. A test that read
     * `$order->items()` straight from the test method would therefore be asserting against an empty
     * set and passing for the wrong reason — or, on a status, reading null and failing for one.
     *
     * @template TValue
     *
     * @param  callable():TValue  $work
     * @return TValue
     */
    private function inTenant(callable $work): mixed
    {
        return $this->withinOrganization($this->meridian, $work);
    }

    /** A status key read the way a controller reads it: fresh, inside the tenant. */
    private function statusOf(Model $record): ?string
    {
        return $this->inTenant(fn () => $record->refresh()->loadMissing('status')->statusKey());
    }

    /** What the supplier still owes on the order's first line. */
    private function outstandingOn(PurchaseOrder $order): float
    {
        return $this->inTenant(fn () => (float) $order->items()->first()->outstandingQuantity());
    }

    /** An issued order with one line of 24, ready for something to be delivered against it. */
    private function orderReadyForDelivery(): PurchaseOrder
    {
        $delta = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');

        $request = $this->makeApprovedPurchaseRequest(
            $this->meridian,
            $this->tunde,
            $this->folake,
            'Tie-in spool materials',
            ['project_id' => $this->project->id],
        );

        $rfq = $this->makeRfq($this->meridian, $this->adaeze, $request, [$delta]);

        $this->inTenant(fn () => app(RfqService::class)->issue($rfq, $this->adaeze));

        $quotation = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $delta, 41000);

        $this->inTenant(fn () => app(RfqService::class)
            ->award($rfq, $quotation, $this->adaeze, 'Only compliant offer.'));

        $order = $this->makePurchaseOrder($this->meridian, $this->adaeze, $quotation);

        $this->inTenant(function () use ($order) {
            $service = app(PurchaseOrderService::class);
            $service->submit($order, $this->adaeze);
            $service->approve($order, $this->folake);
            $service->issue($order, $this->folake);
        });

        return $this->inTenant(fn () => $order->refresh());
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
