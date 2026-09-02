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
use App\Services\Concerns\ManagesLineItems;
use App\Services\Concerns\SeparatesDuties;
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
 * WHAT IS DELIBERATELY ABSENT UNTIL PART C: the stock movement. An accepted receipt line is what
 * will post to the stock ledger, and the ledger does not exist yet. Writing half of it now — a
 * balance with no transactions behind it — would be worse than the honest gap, because the first
 * number it produced would look authoritative.
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
     * Take it. This is the line the stock ledger will hang off in part C.
     */
    public function accept(GoodsReceipt $receipt, User $actor, ?string $comment = null): GoodsReceipt
    {
        if ($receipt->isEditable()) {
            throw new \DomainException('Confirm the delivery before accepting it.');
        }

        if ($receipt->statusKey() === 'accepted') {
            return $receipt;
        }

        return DB::transaction(function () use ($receipt, $actor, $comment) {
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
