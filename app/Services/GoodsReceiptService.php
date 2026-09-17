<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\GoodsReceiptConfirmed;
use App\Events\GoodsReceiptDisputed;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Concerns\ManagesLineItems;
use App\Services\Concerns\SeparatesDuties;
use App\Support\FixedRateMoney;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What actually turned up, counted by somebody standing next to it.
 *
 * A RECEIPT IS AN OBSERVATION, NOT A DECISION, and the whole register is shaped by that. It records
 * two quantities per line — accepted and rejected — rather than one signed figure, because "eight
 * arrived and two were damaged" is a different fact from "six arrived", and only the first can be
 * argued with the supplier. Nothing here infers one from the other.
 *
 * THE RECEIVER IS NOT THE BUYER, unless the company has said otherwise. The person who raised the
 * order confirming its delivery is the oldest procurement fraud there is, and also completely
 * normal on a six-person job where one person does both. So it is a setting —
 * `procurement.separate_buyer_and_receiver` — enforced here rather than in the policy, and recorded
 * on the activity entry whenever the company has switched it off and the two are the same person.
 *
 * THE ORDER'S STATE IS RECOMPUTED, NEVER SET. Confirming or accepting a receipt calls
 * PurchaseOrderService::refreshReceiptProgress(), which adds up the accepted quantities and decides
 * between issued, part_received and received. Nobody clicks either of the last two.
 *
 * AND THE GOODS BECOME STOCK HERE. `postToStock()` is the middle link of the §90 chain — purchase
 * request, order, delivery, ledger, and eventually a project cost — and it fires on ACCEPTANCE
 * rather than on confirmation, because stock the company has not agreed to keep is not stock. It
 * runs inside the acceptance transaction and only for lines that can be traced back to a catalogue
 * item, which is a smaller set than "every line" and is explained in full on the method.
 */
class GoodsReceiptService extends RegisterService
{
    use ManagesLineItems, SeparatesDuties;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly OptionList $options,
        private readonly PurchaseOrderService $orders,
        private readonly StockService $stock,
        private readonly StockItemService $catalogue,
        private readonly ProjectCostService $costs,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'goods_receipt';
    }

    protected function modelClass(): string
    {
        return GoodsReceipt::class;
    }

    /** @return class-string<Model> */
    protected function itemClass(): string
    {
        return GoodsReceiptItem::class;
    }

    protected function itemForeignKey(): string
    {
        return 'goods_receipt_id';
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'purchase_order_id',
            'received_by', 'received_at',
            'inspected_by', 'inspected_at',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var GoodsReceipt $record */
        $order = $this->resolveOrder($attributes);

        if (! $order->acceptsReceipts()) {
            throw new \DomainException(
                'Deliveries can only be recorded against an order that has been issued and is still open.'
            );
        }

        // Throws when the control is on and broken; returns true when the company has switched it
        // off and the two are the same person, which afterCreate() then writes onto the trail.
        $this->assertSeparateDuties(
            $this->buyerOf($order),
            $actor,
            'procurement.separate_buyer_and_receiver',
            'You raised this order, so somebody else has to receive the delivery against it.',
        );

        $record->purchase_order_id = $order->getKey();

        // Where it landed, defaulting to where the order said to send it.
        if (blank($record->location_id)) {
            $record->location_id = $order->location_id;
        }

        // Named here so a store that cannot take the goods is refused while the receipt is still
        // being written, rather than three days later in the middle of accepting it.
        $record->warehouse_id = $this->resolveWarehouse($attributes)?->getKey();
    }

    /** @param  array<string,mixed>  $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var GoodsReceipt $record */
        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor, record: false);
        }

        // Recomputed rather than stashed on the service: a service is resolved once per request
        // and a leftover flag on it is a waiver reported against the wrong record.
        if ($this->waivedFor($record, $actor)) {
            $this->activity->record(
                $record,
                'duties_waived',
                $actor->name.' recorded this delivery against an order they raised themselves.',
                ['self_received' => true],
                $actor,
                $this->projectIdOf($record),
            );
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var GoodsReceipt $record */
        // A confirmed count is evidence. Correcting one means a second receipt or a dispute, not a
        // quiet edit of the figure the supplier was told (§84 — the policy says this too).
        if (! $record->isEditable()) {
            throw new \DomainException('A confirmed delivery record cannot be edited.');
        }

        // The same door on the way back in. `warehouse_id` is an ordinary attribute as far as the
        // base class is concerned, so an edit could otherwise move the delivery into a store the
        // create path would have refused (§84).
        if (array_key_exists('warehouse_id', $attributes)) {
            $attributes['warehouse_id'] = $this->resolveWarehouse($attributes)?->getKey();
        }

        $record = parent::update($record, $attributes, $actor);

        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor);
        }

        return $record;
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var GoodsReceipt $record */
        return $actor->name.' started recording a delivery against '
            .($record->purchaseOrder?->number ?? 'a purchase order').'.';
    }

    // ================================================================== the lines

    /**
     * A receipt line is a line on the ORDER with two counts against it.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function itemAttributes(array $row, Model $parent): array
    {
        /** @var GoodsReceipt $parent */
        $orderItemId = (int) ($row['purchase_order_item_id'] ?? 0);

        // The line has to belong to the order this receipt is against. Without this a posted id
        // records a delivery against somebody else's order — including one in another project the
        // receiver cannot open (§84, §94).
        $orderItem = $parent->purchaseOrder?->items()->whereKey($orderItemId)->first();

        if ($orderItem === null) {
            throw new \DomainException('That line is not on the order this delivery is against.');
        }

        $accepted = max(0.0, (float) ($row['quantity_accepted'] ?? 0));
        $rejected = max(0.0, (float) ($row['quantity_rejected'] ?? 0));

        // A refusal without a reason is a number the supplier cannot answer and the buyer cannot
        // chase. Required here rather than only in the form request, for the same reason as always.
        if ($rejected > 0.0 && blank($row['rejection_reason'] ?? null)) {
            throw new \DomainException('Say why '.$orderItem->description.' was rejected.');
        }

        return [
            'purchase_order_item_id' => $orderItem->getKey(),
            'quantity_accepted' => $accepted,
            'quantity_rejected' => $rejected,
            'rejection_reason' => $rejected > 0.0 ? (string) $row['rejection_reason'] : null,
            'condition' => $this->condition($row['condition'] ?? null, $parent),
            'batch_ref' => blank($row['batch_ref'] ?? null) ? null : (string) $row['batch_ref'],
            'expires_on' => blank($row['expires_on'] ?? null) ? null : $row['expires_on'],
            'notes' => blank($row['notes'] ?? null) ? null : (string) $row['notes'],
        ];
    }

    /**
     * A line with nothing counted on it is not a line.
     *
     * The form shows every outstanding order line so the receiver can tick off what arrived; the
     * ones that did not arrive are left at zero and must not become receipt rows, or the order
     * would show a delivery of nothing against them.
     *
     * @param  array<string,mixed>  $row
     */
    protected function itemIsEmpty(array $row): bool
    {
        if (blank($row['purchase_order_item_id'] ?? null)) {
            return true;
        }

        return (float) ($row['quantity_accepted'] ?? 0) <= 0.0
            && (float) ($row['quantity_rejected'] ?? 0) <= 0.0;
    }

    // ================================================================== the round

    /**
     * The count is finished and the delivery is real. From here the figures stop being editable and
     * the order recomputes where it stands.
     */
    public function confirm(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        if (! $receipt->isEditable()) {
            throw new \DomainException('This delivery has already been confirmed.');
        }

        if ($receipt->items()->doesntExist()) {
            throw new \DomainException('A delivery record needs at least one line on it.');
        }

        return DB::transaction(function () use ($receipt, $actor) {
            $receipt->forceFill([
                'received_by' => $receipt->received_by ?? $actor->getKey(),
                'received_at' => $receipt->received_at ?? now(),
            ]);

            $this->moveTo($receipt, 'received');
            $receipt->save();

            $summary = $receipt->summary();

            $this->activity->record(
                $receipt,
                'received',
                $actor->name.' confirmed the delivery on '.$this->label($receipt).'.',
                $summary,
                $actor,
                $this->projectIdOf($receipt),
            );

            $this->refreshOrder($receipt, $actor);

            GoodsReceiptConfirmed::dispatch($receipt, (int) $actor->getKey());

            return $receipt;
        });
    }

    /** Somebody with the competence to judge it has looked at it. */
    public function inspect(GoodsReceipt $receipt, User $actor, ?string $notes = null): GoodsReceipt
    {
        if (! in_array($receipt->statusKey(), ['received', 'inspected'], true)) {
            throw new \DomainException('Only a confirmed delivery can be inspected.');
        }

        $receipt->forceFill([
            'inspected_by' => $actor->getKey(),
            'inspected_at' => now(),
            'inspection_notes' => $notes ?? $receipt->inspection_notes,
        ]);

        $this->moveTo($receipt, 'inspected');
        $receipt->save();

        $this->activity->record(
            $receipt,
            'inspected',
            $actor->name.' inspected the goods on '.$this->label($receipt).'.',
            array_filter(['notes' => $notes]),
            $actor,
            $this->projectIdOf($receipt),
        );

        return $receipt;
    }

    /**
     * Take it — and, where the receipt names a store, put it on the shelf. See postToStock().
     */
    public function accept(GoodsReceipt $receipt, User $actor, ?string $comment = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $comment) {
            GoodsReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
            $receipt->refresh();

            if ($receipt->isEditable()) {
                throw new \DomainException('Confirm the delivery before accepting it.');
            }

            if ($receipt->statusKey() === 'accepted') {
                return $receipt;
            }

            $this->moveTo($receipt, 'accepted');
            $receipt->save();

            $this->activity->record(
                $receipt,
                'accepted',
                $actor->name.' accepted the goods on '.$this->label($receipt).'.',
                array_filter([...$receipt->summary(), 'comment' => $comment]),
                $actor,
                $this->projectIdOf($receipt),
            );

            $this->refreshOrder($receipt, $actor);

            // AND HERE THE GOODS BECOME STOCK. Inside the same transaction as the acceptance, so
            // there is no state in which the receipt reads accepted and the store has not been
            // told — which was the whole risk of leaving this to an event listener.
            $this->postToStock($receipt, $actor);
            $this->costs->postGoodsReceipt($receipt, $actor);

            return $receipt;
        });
    }

    /**
     * Something is wrong with it and the supplier has to answer.
     *
     * The order is recomputed anyway: whatever WAS accepted still arrived, and holding the whole
     * order at "issued" because part of a delivery is in dispute would misreport the rest of it.
     */
    public function dispute(GoodsReceipt $receipt, User $actor, string $reason): GoodsReceipt
    {
        if ($receipt->isEditable()) {
            throw new \DomainException('Confirm the delivery before raising a dispute on it.');
        }

        if (blank($reason)) {
            throw new \DomainException('A dispute has to say what is wrong.');
        }

        return DB::transaction(function () use ($receipt, $actor, $reason) {
            $this->moveTo($receipt, 'disputed');
            $receipt->save();

            $this->activity->record(
                $receipt,
                'disputed',
                $actor->name.' raised a dispute on '.$this->label($receipt).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($receipt),
            );

            $this->refreshOrder($receipt, $actor);

            GoodsReceiptDisputed::dispatch($receipt, $reason, (int) $actor->getKey());

            return $receipt;
        });
    }

    /**
     * Which job this delivery belongs to.
     *
     * A RECEIPT HAS NO PROJECT COLUMN, on purpose — see postToStock(). Without this override every
     * activity entry a delivery makes would be recorded against no project, and a project timeline
     * would show the order being raised and then nothing at all until the invoice. The order is
     * where the commitment was made, so the order is what answers the question.
     */
    protected function projectIdOf(Model $record): ?int
    {
        /** @var GoodsReceipt $record */
        $id = $record->purchaseOrder?->project_id;

        return $id === null ? null : (int) $id;
    }

    // ================================================================== into the store

    /**
     * Put the accepted quantities onto the shelf.
     *
     * FOUR REASONS A LINE PRODUCES NO MOVEMENT, and all four are ordinary rather than exceptional:
     *
     *   1. The receipt names no store. Plenty of deliveries go straight to a site and are consumed
     *      the same week; forcing every one of them through a warehouse would mean inventing
     *      warehouses that do not exist so the software will let people work.
     *   2. Nothing was accepted on the line — it all came off the lorry damaged.
     *   3. Nobody catalogued what was bought. See GoodsReceiptItem::stockItem(): the chain back to
     *      a catalogue item runs through the requisition, and a direct order for a one-off machined
     *      part has no requisition and never will.
     *   4. The line has already been posted. See the same model's `movements()`.
     *
     * None of the four is an error and none stops the acceptance. The receipt is a record of what
     * arrived; the ledger is a record of what the store now holds; a delivery that never reached a
     * store is a true statement about both.
     *
     * THE COST CARRIED IN IS THE ORDER PRICE, not the receipt's own idea of one, because the order
     * is what will be paid. It is the number the weighted average is computed from — see
     * StockService::post() — and taking it from anywhere else would let the value of stock on hand
     * drift away from what the company was invoiced.
     */
    private function postToStock(GoodsReceipt $receipt, User $actor): void
    {
        $warehouse = $receipt->warehouse;

        if ($warehouse === null) {
            return;
        }

        $receipt->unsetRelation('items');

        /*
         * WHAT JOB IT WAS BOUGHT FOR COMES FROM THE ORDER, because a receipt has no project of its
         * own and never should: goods are committed to a job when the order is placed, and a
         * storekeeper counting a lorry is in no position to reassign them. Carrying it onto the
         * movement is what lets the cost of a job be answered from the ledger rather than from the
         * pile of orders somebody has to remember (§90).
         */
        $order = $receipt->purchaseOrder;
        $projectId = $order?->project_id === null ? null : (int) $order->project_id;
        $orgUnitId = $order?->org_unit_id === null ? null : (int) $order->org_unit_id;

        $posted = 0;

        foreach ($receipt->items()->with('purchaseOrderItem.requestItem.stockItem.status', 'movements')->get() as $line) {
            /** @var GoodsReceiptItem $line */
            $quantity = (float) $line->quantity_accepted;
            $item = $line->stockItem();

            if ($quantity <= 0 || $item === null || $line->movements->isNotEmpty()) {
                continue;
            }

            $unitPrice = (float) FixedRateMoney::toBase(
                $line->purchaseOrderItem?->unit_price ?? 0,
                $order?->currency,
                $order?->exchange_rate,
                $actor->organization?->currency,
                4,
            );

            $this->stock->receive($warehouse, $item, $quantity, $actor, [
                'unit_cost' => $unitPrice,
                'project_id' => $projectId,
                'org_unit_id' => $orgUnitId,
                'source_type' => 'goods_receipt_item',
                'source_id' => $line->getKey(),
                'batch_ref' => $line->batch_ref,
                'expires_on' => $line->expires_on,
                'reason' => 'Received on '.$receipt->number,
                'occurred_at' => $receipt->received_at ?? now(),
            ]);

            // What the company last actually paid for this, kept on the catalogue entry so the next
            // requisition can be estimated from evidence rather than from memory.
            $this->catalogue->recordPurchasePrice($item, $unitPrice);

            $posted++;
        }

        if ($posted === 0) {
            return;
        }

        $this->activity->record(
            $receipt,
            'stocked',
            $posted.' '.($posted === 1 ? 'line' : 'lines').' from '.$this->label($receipt)
            .' went into the '.$warehouse->name.' store.',
            ['lines' => $posted, 'warehouse' => $warehouse->code],
            $actor,
            $this->projectIdOf($receipt),
        );
    }

    // ================================================================== helpers

    /**
     * Ask the order to recount itself.
     *
     * The relations are dropped first because the sums are computed from loaded collections, and a
     * receipt saved a line ago would otherwise be added up out of a stale copy.
     */
    private function refreshOrder(GoodsReceipt $receipt, User $actor): void
    {
        $order = $receipt->purchaseOrder;

        if ($order === null) {
            return;
        }

        $order->unsetRelation('items');
        $order->load('items.receiptItems');

        $this->orders->refreshReceiptProgress($order, $actor);
    }

    /** @param  array<string,mixed>  $attributes */
    private function resolveOrder(array $attributes): PurchaseOrder
    {
        $id = isset($attributes['purchase_order_id']) ? (int) $attributes['purchase_order_id'] : 0;

        // Tenant-scoped, so another organization's order is not found rather than refused (§75).
        $order = $id > 0 ? PurchaseOrder::query()->whereKey($id)->first() : null;

        if ($order === null) {
            throw new \DomainException('A delivery record has to say which order it is against.');
        }

        return $order;
    }

    /**
     * Which store the goods are going into, if any.
     *
     * NULL IS AN ANSWER, NOT A FAILURE. A delivery that never sees a store is ordinary, and the
     * receipt is complete without one; see postToStock() for the four ways a line produces no
     * movement.
     *
     * BUT A NAMED STORE IS CHECKED TWICE. The form request already scoped the id to the tenant;
     * this repeats the lookup because the form is one door and a seeder, an import or another
     * service is another, and the rule belongs where the data is written (§84).
     *
     * A STORE THAT WILL NOT TAKE MOVEMENTS IS REFUSED HERE rather than at acceptance. Acceptance is
     * one transaction — status, order recount, ledger — and a refusal from StockService in the
     * middle of it rolls the whole acceptance back, so the storekeeper is told at the moment they
     * are still able to do something about it.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function resolveWarehouse(array $attributes): ?Warehouse
    {
        $id = (int) ($attributes['warehouse_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        // Tenant-scoped, so another organization's store is simply not there (§75).
        $warehouse = Warehouse::query()->with('status')->whereKey($id)->first();

        if ($warehouse === null) {
            throw new \DomainException('That store could not be found.');
        }

        if (! $warehouse->acceptsMovements()) {
            throw new \DomainException(
                $warehouse->name.': '.($warehouse->movementRefusalReason() ?? 'that store is not taking goods.')
            );
        }

        return $warehouse;
    }

    /**
     * Whose order this is, for the segregation check.
     *
     * EVERY HAND THAT PUT THE ORDER OUT, not the last one. An order is commonly raised by a buyer,
     * submitted by the same buyer and issued by a manager, and `issued_by` alone would then name
     * only the manager — leaving the buyer free to certify the delivery of their own order, which
     * is the exact arrangement the control exists to prevent.
     *
     * Not the approver: the approver is the second pair of eyes on the commitment, and making them
     * ineligible to receive as well would leave a two-person department unable to complete a
     * purchase at all.
     *
     * @return array<int,int>
     */
    private function buyerOf(PurchaseOrder $order): array
    {
        return array_values(array_unique(array_map(
            static fn ($id) => (int) $id,
            array_filter([$order->issued_by, $order->submitted_by, $order->created_by]),
        )));
    }

    /**
     * Did the company's segregation control get waived on this receipt?
     *
     * Only ever called after beforeCreate() has already let the record through, so a true here
     * means the setting is off and the receiver is the buyer — never that the rule was broken.
     */
    private function waivedFor(GoodsReceipt $receipt, User $actor): bool
    {
        $order = $receipt->purchaseOrder;

        if ($order === null || $this->settings->bool('procurement.separate_buyer_and_receiver', true, $receipt->organization_id)) {
            return false;
        }

        return in_array((int) $actor->getKey(), $this->buyerOf($order), true);
    }

    /** Slugged, and always one of the company's own words (§84). Null is allowed — see the column. */
    private function condition(mixed $value, GoodsReceipt $receipt): ?string
    {
        if (blank($value)) {
            return null;
        }

        $allowed = $this->options->keys(
            'goods_receipt.conditions',
            GoodsReceipt::DEFAULT_CONDITIONS,
            $receipt->organization_id,
        );

        $slug = OptionList::slug((string) $value);

        return in_array($slug, $allowed, true) ? $slug : null;
    }
}
