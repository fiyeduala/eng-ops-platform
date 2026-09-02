<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrderService;
use App\Services\RfqService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsProcurement;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * §84 and §94 applied to the register that holds the money.
 *
 * Procurement is where guessing an id is worth something. A purchase order carries a supplier's
 * name, the prices we agreed with them and the total we are committed to; a quotation carries a
 * rival's number before the award. So this file does not ask whether the pages render — the chain
 * test does that — it asks what happens when somebody types an id that is not theirs.
 *
 * Four shapes of attack, each on the door where it would actually be tried:
 *
 *   1. A URL id from another tenant. `/purchase-orders/2` must be NOT FOUND, not FORBIDDEN: a 403
 *      confirms the row exists, and "does an order with id 2 exist at Kestrel" is itself worth
 *      knowing to a competitor.
 *   2. A query-string id on a create form — `?order=`, `?quotation=` — which is the same attack
 *      one step earlier, aimed at the prefill rather than the page.
 *   3. A posted foreign key. The form is the honest door; the POST body is not, and the rule has
 *      to live where the data is written.
 *   4. An id that is in the right tenant and still the wrong record: a quotation from a different
 *      enquiry, offered as the winner of this one.
 *
 * Throughout, the refusal must not describe what it refused. Every assertion that a message does
 * not contain "Kestrel" is a §84 assertion, not a cosmetic one.
 */
final class ProcurementAccessTest extends TestCase
{
    use BuildsCommercial, BuildsProcurement, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private OrgUnit $unit;

    private Project $project;

    /** Meridian's buyer, who holds everything procurement. */
    private User $adaeze;

    /** Meridian's approver. */
    private User $folake;

    /** Meridian's requester. */
    private User $tunde;

    /** Meridian's storekeeper: goods receipts and nothing else. */
    private User $segun;

    /** Kestrel's buyer. Another tenant entirely. */
    private User $chidi;

    /** Kestrel's approver. Their own duties separate, exactly as Meridian's do. */
    private User $ngozi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->unit = $this->makeUnit($this->meridian, 'Projects', OrgUnitType::Division);

        $this->adaeze = $this->makePerson($this->meridian, 'Adaeze Nwachukwu', $this->unit, [
            $this->makeRole($this->meridian, 'Buyer', $this->grants(
                ['purchase_request', 'rfq', 'quotation', 'purchase_order', 'goods_receipt', 'vendor'],
                ['rfq.issue', 'rfq.manage', 'quotation.compare', 'quotation.view_confidential', 'purchase_order.submit', 'purchase_request.submit'],
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

        $this->tunde = $this->makePerson($this->meridian, 'Tunde Bello', $this->unit, [
            $this->makeRole($this->meridian, 'Site Engineer', $this->grants(['purchase_request'])),
        ]);

        $this->segun = $this->makePerson($this->meridian, 'Segun Aluko', $this->unit, [
            $this->makeRole($this->meridian, 'Storekeeper', $this->grants(
                ['goods_receipt'],
                ['purchase_order.view'],
            )),
        ]);

        $this->project = $this->makeProject($this->meridian, $this->adaeze, 'Ikeja Tie-in Package');

        $this->kestrel = $this->makeOrganization('Kestrel Energy');
        $kestrelUnit = $this->makeUnit($this->kestrel, 'Supply Chain', OrgUnitType::Division);

        $this->chidi = $this->makePerson($this->kestrel, 'Chidi Okonkwo', $kestrelUnit, [
            $this->makeRole($this->kestrel, 'Buyer', $this->grants(
                ['purchase_request', 'rfq', 'quotation', 'purchase_order', 'goods_receipt', 'vendor'],
                ['rfq.issue', 'rfq.manage', 'quotation.compare', 'quotation.view_confidential', 'purchase_order.submit', 'purchase_request.submit'],
            )),
        ]);

        $this->ngozi = $this->makePerson($this->kestrel, 'Ngozi Balogun', $kestrelUnit, [
            $this->makeRole($this->kestrel, 'Procurement Manager', $this->grants(
                ['purchase_request', 'purchase_order', 'goods_receipt', 'quotation', 'rfq'],
                ['purchase_request.approve', 'purchase_order.approve', 'purchase_order.issue'],
            )),
        ]);
    }

    // ============================================================== 1. a URL id from another tenant

    #[Test]
    public function another_tenants_records_are_not_found_rather_than_forbidden(): void
    {
        $order = $this->kestrelOrder();

        // Adaeze holds every procurement permission in her own company, so the middleware lets her
        // through the door. What stops her is the tenant, and it stops her by not finding the row.
        $this->actingFresh($this->adaeze)
            ->get(route('purchase-orders.show', $order))
            ->assertNotFound();

        $this->actingFresh($this->adaeze)
            ->get(route('quotations.show', $order->quotation_id))
            ->assertNotFound();

        $this->actingFresh($this->adaeze)
            ->get(route('rfqs.show', $this->kestrelRfqId))
            ->assertNotFound();

        $this->actingFresh($this->adaeze)
            ->get(route('purchase-requests.show', $this->kestrelRequestId))
            ->assertNotFound();
    }

    #[Test]
    public function another_tenants_order_cannot_be_edited_or_moved_on(): void
    {
        $order = $this->kestrelOrder();

        // The write doors, which is where a 404 matters more than on a read: an approval posted
        // across a tenant boundary would be a commitment nobody at Kestrel ever made.
        $this->actingFresh($this->folake)
            ->get(route('purchase-orders.edit', $order))
            ->assertNotFound();

        $this->actingFresh($this->folake)
            ->post(route('purchase-orders.approve', $order), ['comment' => 'Fine by me.'])
            ->assertNotFound();

        $this->actingFresh($this->folake)
            ->delete(route('purchase-orders.destroy', $order))
            ->assertNotFound();

        $this->assertNotNull($this->withinOrganization($this->kestrel, fn () => PurchaseOrder::query()->find($order->id)));
    }

    // ====================================================== 2. a guessed id on a create form

    #[Test]
    public function a_guessed_order_id_prefills_nothing_on_the_receipt_form(): void
    {
        $order = $this->kestrelOrder();

        // The form takes `?order=` so the "record a delivery" button on an order page can carry the
        // order across. Typed by hand with somebody else's id it must degrade to the chooser, and
        // must not read the supplier's name out of another company's order (§75).
        $response = $this->actingFresh($this->segun)
            ->get(route('goods-receipts.create', ['order' => $order->id]))
            ->assertOk();

        $response->assertDontSee('Kestrel Fabrication');
        $response->assertDontSee($order->number);
        // The chooser, not the receipt form: nothing was prefilled.
        $response->assertSee('name="order"', false);
    }

    #[Test]
    public function a_guessed_quotation_id_prefills_nothing_on_the_order_form(): void
    {
        $order = $this->kestrelOrder();

        $response = $this->actingFresh($this->adaeze)
            ->get(route('purchase-orders.create', ['quotation' => $order->quotation_id]))
            ->assertOk();

        $response->assertDontSee('Kestrel Fabrication');
        $this->assertStringNotContainsString('41000', $response->getContent());
    }

    // ================================================== 3. a posted foreign key

    #[Test]
    public function a_delivery_cannot_be_posted_against_another_tenants_order(): void
    {
        $order = $this->kestrelOrder();

        $this->actingFresh($this->segun)
            ->post(route('goods-receipts.store'), [
                'purchase_order_id' => $order->id,
                'received_on' => now()->toDateString(),
                'delivery_note_ref' => 'DN-9001',
                'items' => [
                    ['purchase_order_item_id' => 1, 'quantity_accepted' => 24, 'condition' => 'Good'],
                ],
            ])
            ->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, $this->withinOrganization($this->meridian, fn () => GoodsReceipt::query()->count()));
        $this->assertSame(0, $this->withinOrganization($this->kestrel, fn () => GoodsReceipt::query()->count()));

        // "Could not be found" is the same answer for a row at Kestrel and a row that never
        // existed. Anything else turns a validation message into a probe.
        $this->assertStringNotContainsString(
            'Kestrel',
            (string) session('errors')?->first('purchase_order_id'),
        );
    }

    #[Test]
    public function a_delivery_line_cannot_name_an_item_from_another_order(): void
    {
        $mine = $this->meridianOrder();
        $theirs = $this->kestrelOrder();
        $foreignLine = $this->withinOrganization($this->kestrel, fn () => $theirs->items()->first());

        // The header is mine; only the LINE points somewhere else. A receipt built from this would
        // credit Kestrel's order line with a delivery Meridian recorded, and the arithmetic that
        // closes an order would then run over two companies' books at once.
        $this->actingFresh($this->segun)
            ->post(route('goods-receipts.store'), [
                'purchase_order_id' => $mine->id,
                'received_on' => now()->toDateString(),
                'items' => [
                    ['purchase_order_item_id' => $foreignLine->id, 'quantity_accepted' => 24, 'condition' => 'Good'],
                ],
            ]);

        $written = $this->withinOrganization($this->kestrel, fn () => $foreignLine->receiptItems()->count());

        $this->assertSame(0, $written, 'a line from another tenant’s order was written against');
    }

    // ================================================== 4. the right tenant, the wrong record

    #[Test]
    public function an_enquiry_cannot_be_awarded_to_a_quotation_from_another_enquiry(): void
    {
        $delta = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');

        $first = $this->issuedRfq($delta);
        $second = $this->issuedRfq($delta);

        $stranger = $this->makeQuotation($this->meridian, $this->adaeze, $second, $delta, 12000);

        // Both records are Adaeze's own, in her own tenant, and she holds `rfq.manage`. The refusal
        // is not about permission at all — it is about the two ids belonging together (§84).
        $this->expectException(\DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(RfqService::class)
            ->award($first, $stranger, $this->adaeze, 'Cheapest.'));
    }

    #[Test]
    public function an_order_cannot_be_raised_from_a_quotation_that_did_not_win(): void
    {
        $delta = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');
        $rfq = $this->issuedRfq($delta);
        $losing = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $delta, 99000);

        // Nothing has been awarded, so no quotation on this enquiry is the winner yet. Raising an
        // order from one anyway would commit the company to a price nobody chose.
        $this->expectException(\DomainException::class);

        $this->withinOrganization($this->meridian, fn () => app(PurchaseOrderService::class)
            ->fromQuotation($losing, [], $this->adaeze));
    }

    // ================================================== 5. permission, inside one tenant

    #[Test]
    public function the_registers_a_person_does_not_hold_are_closed_to_them(): void
    {
        // Segun counts deliveries. He is trusted with a purchase order — he has to read the one he
        // is receiving against — and with nothing else in the chain.
        $this->actingFresh($this->segun)->get(route('goods-receipts.index'))->assertOk();
        $this->actingFresh($this->segun)->get(route('purchase-orders.index'))->assertOk();

        $this->actingFresh($this->segun)->get(route('quotations.index'))->assertForbidden();
        $this->actingFresh($this->segun)->get(route('rfqs.index'))->assertForbidden();
        $this->actingFresh($this->segun)->get(route('purchase-requests.index'))->assertForbidden();

        // Tunde raises requisitions. Prices from suppliers are not his business, and the comparison
        // screen is the one that would show him all of them at once (§61).
        $this->actingFresh($this->tunde)->get(route('purchase-requests.index'))->assertOk();
        $this->actingFresh($this->tunde)->get(route('quotations.index'))->assertForbidden();
        $this->actingFresh($this->tunde)->get(route('purchase-orders.index'))->assertForbidden();
    }

    #[Test]
    public function an_index_lists_this_tenant_and_no_other(): void
    {
        $this->kestrelOrder();
        $mine = $this->meridianOrder();

        $this->actingFresh($this->adaeze)
            ->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee('Kestrel Fabrication');

        // And the search box obeys the same rule as the page, rather than reaching past it (§61).
        $this->actingFresh($this->adaeze)
            ->get(route('purchase-orders.index', ['q' => 'Kestrel']))
            ->assertOk()
            ->assertDontSee('Kestrel Fabrication');
    }

    #[Test]
    public function a_price_from_a_supplier_is_confidential_by_default(): void
    {
        $delta = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');
        $rfq = $this->issuedRfq($delta);
        $quotation = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $delta, 41000);

        // The buyer running the enquiry reads it, which is the point of the register.
        $this->actingFresh($this->adaeze)
            ->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee('41,000.00');

        // Ifeoma holds `quotation.view` across the whole organization and is still not on this
        // enquiry. A rival's number before the award is the one leak that makes a tender unsound,
        // so the column defaults to `confidential` and Gate 5 asks for reach, not permission.
        $ifeoma = $this->makePerson($this->meridian, 'Ifeoma Nwosu', $this->unit, [
            $this->makeRole($this->meridian, 'Planner', $this->grants(['quotation'])),
        ]);

        $this->actingFresh($ifeoma)
            ->get(route('quotations.show', $quotation))
            ->assertForbidden();

        // And the register does not name it either — a list that shows the number and hides the
        // page would have leaked most of what mattered (§61).
        $this->actingFresh($ifeoma)
            ->get(route('quotations.index'))
            ->assertOk()
            ->assertDontSee($quotation->number);
    }

    // ================================================================== fixtures

    private ?int $kestrelRfqId = null;

    private ?int $kestrelRequestId = null;

    /** An issued Kestrel order, with everything behind it, built the way Kestrel would build it. */
    private function kestrelOrder(): PurchaseOrder
    {
        $vendor = $this->makeApprovedVendor($this->kestrel, $this->chidi, 'Kestrel Fabrication');

        $request = $this->makeApprovedPurchaseRequest($this->kestrel, $this->chidi, $this->ngozi);
        $this->kestrelRequestId = (int) $request->getKey();

        $rfq = $this->makeRfq($this->kestrel, $this->chidi, $request, [$vendor]);
        $this->kestrelRfqId = (int) $rfq->getKey();

        $this->withinOrganization($this->kestrel, fn () => app(RfqService::class)->issue($rfq, $this->chidi));

        $quotation = $this->makeQuotation($this->kestrel, $this->chidi, $rfq, $vendor, 41000);

        $this->withinOrganization($this->kestrel, fn () => app(RfqService::class)
            ->award($rfq, $quotation, $this->chidi, 'Only offer.'));

        $order = $this->makePurchaseOrder($this->kestrel, $this->chidi, $quotation);

        $this->withinOrganization($this->kestrel, function () use ($order) {
            $service = app(PurchaseOrderService::class);
            $service->submit($order, $this->chidi);
            $service->approve($order, $this->ngozi);
            $service->issue($order, $this->ngozi);
        });

        return $this->withinOrganization($this->kestrel, fn () => $order->refresh());
    }

    /** The same thing at Meridian, for the "mine renders, theirs does not" pairs. */
    private function meridianOrder(): PurchaseOrder
    {
        $vendor = $this->makeApprovedVendor($this->meridian, $this->adaeze, 'Delta Fabrication');
        $request = $this->makeApprovedPurchaseRequest($this->meridian, $this->tunde, $this->folake);
        $rfq = $this->makeRfq($this->meridian, $this->adaeze, $request, [$vendor]);

        $this->withinOrganization($this->meridian, fn () => app(RfqService::class)->issue($rfq, $this->adaeze));

        $quotation = $this->makeQuotation($this->meridian, $this->adaeze, $rfq, $vendor, 41000);

        $this->withinOrganization($this->meridian, fn () => app(RfqService::class)
            ->award($rfq, $quotation, $this->adaeze, 'Only offer.'));

        $order = $this->makePurchaseOrder($this->meridian, $this->adaeze, $quotation);

        $this->withinOrganization($this->meridian, function () use ($order) {
            $service = app(PurchaseOrderService::class);
            $service->submit($order, $this->adaeze);
            $service->approve($order, $this->folake);
            $service->issue($order, $this->folake);
        });

        return $this->withinOrganization($this->meridian, fn () => $order->refresh());
    }

    /**
     * A Meridian enquiry, issued and taking quotations from the supplier it invited.
     *
     * The vendor is a parameter because a quotation may only be entered from a supplier ON the
     * invitation list — the register refuses to record a price from somebody nobody asked — so a
     * fixture that minted its own vendor would be testing that rule instead of the one in hand.
     */
    private function issuedRfq(Vendor $vendor): Rfq
    {
        $request = $this->makeApprovedPurchaseRequest($this->meridian, $this->tunde, $this->folake);
        $rfq = $this->makeRfq($this->meridian, $this->adaeze, $request, [$vendor]);

        $this->withinOrganization($this->meridian, fn () => app(RfqService::class)->issue($rfq, $this->adaeze));

        return $rfq;
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
            foreach (['view', 'create', 'edit', 'delete'] as $ability) {
                $grants[$module.'.'.$ability] = 'organization';
            }
        }

        foreach ($extra as $key) {
            $grants[$key] = 'organization';
        }

        return $grants;
    }
}
