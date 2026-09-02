<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\QuotationService;
use App\Services\RfqService;

/**
 * Fixtures for the procurement chain.
 *
 * EVERY LINK IS MADE THE WAY THE APPLICATION MAKES IT. An enquiry comes from `fromPurchaseRequest`,
 * an order from `fromQuotation`, a receipt from `create` against a live order — never from
 * `Model::create()` with the foreign keys typed in. The whole point of Phase 5 is that the five
 * registers are one chain, and a fixture that assembled the chain by hand would let every test pass
 * while the services that build it in production were broken.
 *
 * THE HELPERS STOP WHERE A DECISION STARTS. `makeRfq()` leaves the enquiry in draft with its
 * suppliers invited; `makePurchaseOrder()` leaves the order in draft. Approving, issuing, awarding
 * and receiving are acts with permissions and segregation-of-duties rules attached, so a test that
 * needs them says so out loud rather than getting them for free from a fixture.
 *
 * Companion to {@see BuildsTenants} and {@see BuildsCommercial}, both of which this assumes.
 */
trait BuildsProcurement
{
    /**
     * A requisition with one line on it, in draft.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makePurchaseRequest(
        Organization $organization,
        User $actor,
        string $title = 'Tie-in spool materials',
        array $attributes = [],
    ): PurchaseRequest {
        $this->provisionConfiguration($organization);

        /** @var PurchaseRequest */
        return $this->withinOrganization($organization, fn () => app(PurchaseRequestService::class)->create([
            'title' => $title,
            'items' => [
                [
                    'description' => '12" carbon steel pipe, schedule 40',
                    'quantity' => 24,
                    'uom' => 'm',
                    'estimated_unit_price' => 45000,
                ],
            ],
            ...$attributes,
        ], $actor));
    }

    /** An approved requisition, which is the only kind an enquiry or an order can come from. */
    protected function makeApprovedPurchaseRequest(
        Organization $organization,
        User $requester,
        User $approver,
        string $title = 'Tie-in spool materials',
        array $attributes = [],
    ): PurchaseRequest {
        $request = $this->makePurchaseRequest($organization, $requester, $title, $attributes);

        return $this->withinOrganization($organization, function () use ($request, $requester, $approver) {
            $service = app(PurchaseRequestService::class);
            $service->submit($request, $requester);
            // A separate approver on purpose: SeparatesDuties refuses the requester by default, and
            // a fixture that quietly used one person would hide that rule from every test built on it.
            $service->approve($request, $approver, 'Budgeted under the tie-in package.');

            return $request->refresh();
        });
    }

    /**
     * A draft enquiry raised from a requisition, with the given suppliers invited.
     *
     * @param  array<int,Vendor>  $vendors
     * @param  array<string,mixed>  $attributes
     */
    protected function makeRfq(
        Organization $organization,
        User $actor,
        PurchaseRequest $request,
        array $vendors,
        array $attributes = [],
    ): Rfq {
        /** @var Rfq */
        return $this->withinOrganization($organization, function () use ($request, $vendors, $attributes, $actor) {
            $service = app(RfqService::class);
            $rfq = $service->fromPurchaseRequest($request, $attributes, $actor);

            foreach ($vendors as $vendor) {
                $service->invite($rfq, $vendor, $actor);
            }

            return $rfq->refresh();
        });
    }

    /**
     * A quotation against an enquiry, priced line for line off the enquiry's own items.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeQuotation(
        Organization $organization,
        User $actor,
        Rfq $rfq,
        Vendor $vendor,
        float $unitPrice = 42000,
        array $attributes = [],
    ): Quotation {
        /** @var Quotation */
        return $this->withinOrganization($organization, function () use ($rfq, $vendor, $unitPrice, $attributes, $actor) {
            $items = $rfq->items()->orderBy('line_no')->get()->map(fn ($item) => [
                'rfq_item_id' => $item->getKey(),
                'description' => $item->description,
                'quantity' => $item->quantity,
                'uom' => $item->uom,
                'unit_price' => $unitPrice,
            ])->all();

            return app(QuotationService::class)->create([
                'rfq_id' => $rfq->getKey(),
                'vendor_id' => $vendor->getKey(),
                'items' => $items,
                ...$attributes,
            ], $actor);
        });
    }

    /** A draft order raised from a winning quotation. */
    protected function makePurchaseOrder(
        Organization $organization,
        User $actor,
        Quotation $quotation,
        array $attributes = [],
    ): PurchaseOrder {
        /** @var PurchaseOrder */
        return $this->withinOrganization(
            $organization,
            fn () => app(PurchaseOrderService::class)->fromQuotation($quotation, $attributes, $actor)
        );
    }

    /**
     * A delivery entered against an order, in draft until somebody confirms it.
     *
     * The quantities default to everything the order is still owed, because the common case in a
     * test is "it all turned up"; a short delivery is expressed by passing the lines explicitly.
     *
     * @param  array<int,array<string,mixed>>|null  $lines
     * @param  array<string,mixed>  $attributes
     */
    protected function makeGoodsReceipt(
        Organization $organization,
        User $actor,
        PurchaseOrder $order,
        ?array $lines = null,
        array $attributes = [],
    ): GoodsReceipt {
        /** @var GoodsReceipt */
        return $this->withinOrganization($organization, function () use ($order, $lines, $attributes, $actor) {
            $lines ??= $order->items()->orderBy('line_no')->get()->map(fn ($item) => [
                'purchase_order_item_id' => $item->getKey(),
                'quantity_accepted' => $item->outstandingQuantity(),
                'condition' => 'Good',
            ])->all();

            return app(GoodsReceiptService::class)->create([
                'purchase_order_id' => $order->getKey(),
                'items' => $lines,
                ...$attributes,
            ], $actor);
        });
    }
}
