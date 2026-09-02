<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\PurchaseOrderDecided;
use App\Events\PurchaseOrderIssued;
use App\Events\PurchaseOrderSubmitted;
use App\Models\Contract;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Concerns\ManagesLineItems;
use App\Services\Concerns\SeparatesDuties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The commitment. Everything before this is intention; this is the company's money.
 *
 * WHICH IS WHY AN ISSUED ORDER IS NOT EDITABLE. PurchaseOrderPolicy refuses `update` on an issued
 * order to everybody, including whoever holds `purchase_order.manage`, and this service refuses the
 * same thing again on the way in (§84). The supplier is acting on a document; a quantity changed
 * underneath them turns a delivery dispute into an argument about what the order said. Amending an
 * issued order means cancelling it and raising another, which is what the paperwork does too.
 *
 * THE RATE IS FIXED AT AWARD AND NOTHING REVALUES IT. `exchange_rate` is copied off the quotation
 * and never recomputed. An order placed at 1,450 to the dollar is an order at 1,450 to the dollar
 * for as long as it exists, and a system that quietly restates it at today's rate is a system whose
 * committed-cost figure disagrees with every invoice it will receive.
 *
 * `part_received` AND `received` ARE ARITHMETIC, NOT DECISIONS. `refreshReceiptProgress()` is
 * called by GoodsReceiptService from the sum of the accepted quantities and there is no clickable
 * transition to either. A person marking an order fully received is a person overriding a count,
 * and the count is the only thing that can be reconciled against a delivery note.
 *
 * COMPETITION IS CHECKED, NOT ENFORCED. An order above `procurement.rfq_required_above` that did
 * not come from an awarded enquiry or a standing contract asks for a written reason at approval.
 * Refusing it outright would be wrong — emergencies and sole-source suppliers are real — and
 * silence would make the setting decorative.
 */
class PurchaseOrderService extends RegisterService
{
    use ManagesLineItems, SeparatesDuties;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly PurchaseRequestService $requests,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'purchase_order';
    }

    protected function modelClass(): string
    {
        return PurchaseOrder::class;
    }

    /** @return class-string<Model> */
    protected function itemClass(): string
    {
        return PurchaseOrderItem::class;
    }

    protected function itemForeignKey(): string
    {
        return 'purchase_order_id';
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'vendor_id', 'quotation_id', 'contract_id',
            'subtotal', 'total',
            'submitted_by', 'submitted_at',
            'approved_by', 'approved_at',
            'rejected_by', 'rejected_at', 'decision_reason',
            'issued_by', 'issued_at',
            'cancelled_by', 'cancelled_at', 'cancellation_reason',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var PurchaseOrder $record */
        $vendor = $this->resolveVendor($attributes);

        if (! $vendor->canBeUsed()) {
            throw new \DomainException(
                'An order cannot be placed with '.$vendor->name.': '.strtolower((string) $vendor->unusableReason()).'.'
            );
        }

        $record->vendor_id = $vendor->getKey();
        $record->quotation_id = $this->resolveQuotation($attributes, $vendor)?->getKey();
        $record->contract_id = $this->resolveContract($attributes, $vendor)?->getKey();

        if (blank($record->currency)) {
            $record->currency = $actor->organization?->currency;
        }

        if (blank($record->exchange_rate) && $record->currency === $actor->organization?->currency) {
            $record->exchange_rate = 1;
        }

        if (blank($record->ordered_on)) {
            $record->ordered_on = now()->toDateString();
        }

        $record->subtotal = 0;
        $record->total = 0;
    }

    /** @param  array<string,mixed>  $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var PurchaseOrder $record */
        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor, record: false);
        }

        $this->recalculate($record);
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var PurchaseOrder $record */
        // The policy says the same thing; this is the seeder-and-import door. See the docblock.
        if ($record->isIssued()) {
            throw new \DomainException('An issued order cannot be amended. Cancel it and raise a replacement.');
        }

        $record = parent::update($record, $attributes, $actor);

        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor);
        }

        $this->recalculate($record);

        return $record;
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var PurchaseOrder $record */
        return $actor->name.' raised '.$this->label($record)
            .' on '.($record->vendor?->name ?? 'a supplier').'.';
    }

    // ================================================================== from an award

    /**
     * Turn the winning quotation into an order.
     *
     * The prices, the currency, the rate and the terms all come across from the quotation rather
     * than being retyped, because the whole point of the award was that those were the numbers
     * agreed. The header the buyer supplies — delivery address, required date — is layered on top.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function fromQuotation(Quotation $quotation, array $attributes, User $actor): PurchaseOrder
    {
        // ASKED OF THE DATABASE, NOT OF THE OBJECT. The award is written by RfqService, often
        // moments earlier and often on a different instance of this same enquiry — so a `rfq`
        // relation loaded before the award would still be reading `awarded_quotation_id` as null
        // and would refuse the very order the award exists to authorize. A guard that decides
        // whether money may be committed reads the row (§84).
        //
        // The quotation is re-read for the same reason and one more: its prices, its currency and
        // its rate are about to be copied onto a commitment to a supplier, and they must be the
        // figures the register holds rather than whatever the caller happened to have in hand.
        $quotation->refresh();

        $rfq = $quotation->rfq()->first();
        $quotation->setRelation('rfq', $rfq);

        if ($rfq !== null && (int) $rfq->awarded_quotation_id !== (int) $quotation->getKey()) {
            throw new \DomainException('That quotation did not win the enquiry, so an order cannot be raised from it.');
        }

        return DB::transaction(function () use ($quotation, $rfq, $attributes, $actor) {
            // `rfqItem` EAGERLY, AND ONE QUERY RATHER THAN ONE PER LINE. Each order line traces
            // back through the quotation line to the enquiry line to the requisition line, and
            // that last hop is what lets a project accountant open a purchase order and see which
            // requisition asked for it. Read line by line it is an N+1 that nobody notices until a
            // sixty-line order takes a second and a half to raise.
            $lines = $quotation->items()->with('rfqItem')->orderBy('line_no')->get()->map(fn ($item) => [
                'quotation_item_id' => $item->getKey(),
                'purchase_request_item_id' => $item->rfqItem?->purchase_request_item_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'uom' => $item->uom,
                'unit_price' => $item->unit_price,
            ])->all();

            /** @var PurchaseOrder $order */
            $order = $this->create([
                'title' => $attributes['title'] ?? $rfq?->title ?? $quotation->reference,
                'project_id' => $attributes['project_id'] ?? $rfq?->project_id,
                'org_unit_id' => $attributes['org_unit_id'] ?? $rfq?->org_unit_id,
                'payment_terms' => $attributes['payment_terms'] ?? $quotation->payment_terms,
                'delivery_terms' => $attributes['delivery_terms'] ?? $quotation->delivery_terms,
                ...$attributes,
                'vendor_id' => $quotation->vendor_id,
                'quotation_id' => $quotation->getKey(),
                'purchase_request_id' => $attributes['purchase_request_id'] ?? $rfq?->purchase_request_id,
                // Copied from the award and never recomputed — see the class docblock.
                'currency' => $quotation->currency,
                'exchange_rate' => $quotation->exchange_rate,
                'tax_amount' => $quotation->tax_amount ?? 0,
                'items' => $lines,
            ], $actor);

            if ($rfq !== null) {
                $this->activity->record(
                    $rfq,
                    'order_raised',
                    $actor->name.' raised '.$order->number.' from the award on '.$rfq->number.'.',
                    ['purchase_order' => $order->number],
                    $actor,
                    $this->projectIdOf($rfq),
                );
            }

            return $order;
        });
    }

    // ================================================================== the lines and the total

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function itemAttributes(array $row, Model $parent): array
    {
        $quantity = (float) ($row['quantity'] ?? 0);
        $price = (float) ($row['unit_price'] ?? 0);

        return [
            'quotation_item_id' => blank($row['quotation_item_id'] ?? null) ? null : (int) $row['quotation_item_id'],
            'purchase_request_item_id' => blank($row['purchase_request_item_id'] ?? null) ? null : (int) $row['purchase_request_item_id'],
            'description' => (string) $row['description'],
            'specification' => blank($row['specification'] ?? null) ? null : (string) $row['specification'],
            'quantity' => $quantity,
            'uom' => blank($row['uom'] ?? null) ? null : (string) $row['uom'],
            'unit_price' => $price,
            'line_total' => $this->lineTotal($quantity, $price),
            'required_by' => blank($row['required_by'] ?? null) ? null : $row['required_by'],
            'notes' => blank($row['notes'] ?? null) ? null : (string) $row['notes'],
        ];
    }

    public function recalculate(PurchaseOrder $order): PurchaseOrder
    {
        $subtotal = number_format((float) $order->items()->sum('line_total'), 2, '.', '');
        $total = number_format((float) $subtotal + (float) $order->tax_amount, 2, '.', '');

        $order->forceFill(['subtotal' => $subtotal, 'total' => $total]);

        if ($order->isDirty()) {
            $order->save();
        }

        return $order;
    }

    // ================================================================== the decision

    public function submit(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        if ($order->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft order can be submitted for approval.');
        }

        if ($order->items()->doesntExist()) {
            throw new \DomainException('An order cannot be submitted with nothing on it.');
        }

        return DB::transaction(function () use ($order, $actor) {
            $this->recalculate($order);

            $order->forceFill([
                'submitted_by' => $order->submitted_by ?? $actor->getKey(),
                'submitted_at' => now(),
            ]);

            $this->moveTo($order, 'submitted');
            $order->save();

            $this->activity->record(
                $order,
                'submitted',
                $actor->name.' submitted '.$this->label($order).' for approval.',
                ['total' => (string) $order->total, 'currency' => $order->currency],
                $actor,
                $this->projectIdOf($order),
            );

            PurchaseOrderSubmitted::dispatch($order, (int) $actor->getKey());

            return $order;
        });
    }

    /**
     * @param  string|null  $comment  becomes required when the order needed competition it did not have
     */
    public function approve(PurchaseOrder $order, User $actor, ?string $comment = null): PurchaseOrder
    {
        if ($order->statusKey() !== 'submitted') {
            throw new \DomainException('Only a submitted order can be approved.');
        }

        // The same control as on the requisition, and deliberately the same setting: "the person
        // who raised it does not approve it" is one policy a company holds, not two.
        $waived = $this->assertSeparateDuties(
            $this->originatorOf($order),
            $actor,
            'procurement.separate_requester_and_approver',
            'You raised this order, so somebody else has to approve it.',
        );

        $threshold = $this->settings->string('procurement.rfq_required_above', '', $order->organization_id);
        $uncompeted = $order->needsCompetitionJustification($threshold === '' ? null : $threshold);

        if ($uncompeted && blank($comment)) {
            throw new \DomainException(
                'This order is above the figure your organization expects competitive quotations for, '
                    .'and it did not come from an awarded enquiry or a contract. Approving it needs a written reason.'
            );
        }

        return DB::transaction(function () use ($order, $actor, $comment, $waived, $uncompeted) {
            $order->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'decision_reason' => $comment,
            ]);

            $this->moveTo($order, 'approved');
            $order->save();

            $this->activity->record(
                $order,
                'approved',
                $actor->name.' approved '.$this->label($order).'.',
                array_filter([
                    'comment' => $comment,
                    'self_approved' => $waived ?: null,
                    'without_competition' => $uncompeted ?: null,
                ]),
                $actor,
                $this->projectIdOf($order),
            );

            PurchaseOrderDecided::dispatch($order, (int) $actor->getKey());

            return $order;
        });
    }

    public function reject(PurchaseOrder $order, User $actor, string $reason): PurchaseOrder
    {
        if ($order->statusKey() !== 'submitted') {
            throw new \DomainException('Only a submitted order can be rejected.');
        }

        if (blank($reason)) {
            throw new \DomainException('A rejection has to say why.');
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order->forceFill([
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'decision_reason' => $reason,
            ]);

            $this->moveTo($order, 'rejected');
            $order->save();

            $this->activity->record(
                $order,
                'rejected',
                $actor->name.' rejected '.$this->label($order).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($order),
            );

            PurchaseOrderDecided::dispatch($order, (int) $actor->getKey());

            return $order;
        });
    }

    /**
     * Send it to the supplier. From here the order is a commitment and stops being editable.
     */
    public function issue(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        if ($order->statusKey() !== 'approved') {
            throw new \DomainException('Only an approved order can be issued to the supplier.');
        }

        return DB::transaction(function () use ($order, $actor) {
            $order->forceFill([
                'issued_by' => $order->issued_by ?? $actor->getKey(),
                'issued_at' => now(),
            ]);

            $this->moveTo($order, 'issued');
            $order->save();

            $this->activity->record(
                $order,
                'issued',
                $actor->name.' issued '.$this->label($order).' to '.($order->vendor?->name ?? 'the supplier').'.',
                ['vendor' => $order->vendor?->name],
                $actor,
                $this->projectIdOf($order),
            );

            // The requisition finally gets its answer. Marked here rather than at approval because
            // this is the moment somebody outside the company is acting on it.
            if ($order->purchaseRequest instanceof PurchaseRequest) {
                $this->requests->markOrdered($order->purchaseRequest, $actor, $order->number);
            }

            PurchaseOrderIssued::dispatch($order, (int) $actor->getKey());

            return $order;
        });
    }

    public function cancel(PurchaseOrder $order, User $actor, string $reason): PurchaseOrder
    {
        if ($order->isClosed()) {
            throw new \DomainException('That order is already finished with.');
        }

        if (blank($reason)) {
            throw new \DomainException('Cancelling an order has to say why.');
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order->forceFill([
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->moveTo($order, 'cancelled');
            $order->save();

            $this->activity->record(
                $order,
                'cancelled',
                $actor->name.' cancelled '.$this->label($order).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($order),
            );

            return $order;
        });
    }

    // ================================================================== delivery

    /**
     * Recompute where the order stands from the receipts against it. Called by GoodsReceiptService.
     *
     * Only moves between `issued`, `part_received` and `received`, and only ever forward from
     * `issued`. A cancelled or closed order that a late receipt arrives against stays where it is —
     * the receipt is still recorded, because it did arrive, but a closed order does not reopen
     * itself because somebody entered a delivery note a fortnight afterwards.
     */
    public function refreshReceiptProgress(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        if (! in_array($order->statusKey(), ['issued', 'part_received', 'received'], true)) {
            return $order;
        }

        $progress = $order->receiptProgress();
        $was = $order->statusKey();

        $now = match (true) {
            (float) $progress['accepted'] <= 0.0 => 'issued',
            (float) $progress['outstanding'] <= 0.0 => 'received',
            default => 'part_received',
        };

        if ($now === $was) {
            return $order;
        }

        $this->moveTo($order, $now);
        $order->save();

        $this->activity->record(
            $order,
            'delivery_progress',
            $this->label($order).' is now '.($now === 'received' ? 'received in full' : 'partly received').'.',
            [
                'ordered' => $progress['ordered'],
                'accepted' => $progress['accepted'],
                'outstanding' => $progress['outstanding'],
            ],
            $actor,
            $this->projectIdOf($order),
        );

        return $order;
    }

    // ================================================================== resolution

    /**
     * Who raised it, for the segregation check. `submitted_by` first — see
     * PurchaseRequestService::originatorOf() for why the submitter outranks the author.
     */
    private function originatorOf(PurchaseOrder $order): ?int
    {
        $id = $order->submitted_by ?? $order->created_by;

        return $id === null ? null : (int) $id;
    }

    /** @param  array<string,mixed>  $attributes */
    private function resolveVendor(array $attributes): Vendor
    {
        $id = isset($attributes['vendor_id']) ? (int) $attributes['vendor_id'] : 0;

        // Tenant-scoped: another organization's supplier is simply not found (§75, §84).
        $vendor = $id > 0 ? Vendor::query()->whereKey($id)->first() : null;

        if ($vendor === null) {
            throw new \DomainException('An order has to name a supplier.');
        }

        return $vendor;
    }

    /**
     * The quotation, if one was named — and it has to be that supplier's own.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function resolveQuotation(array $attributes, Vendor $vendor): ?Quotation
    {
        $id = isset($attributes['quotation_id']) ? (int) $attributes['quotation_id'] : 0;

        if ($id <= 0) {
            return null;
        }

        $quotation = Quotation::query()->whereKey($id)->first();

        if ($quotation === null) {
            throw new \DomainException('That quotation could not be found.');
        }

        if ((int) $quotation->vendor_id !== (int) $vendor->getKey()) {
            throw new \DomainException('That quotation came from a different supplier.');
        }

        return $quotation;
    }

    /**
     * The contract, if one was named — and it has to be with that supplier.
     *
     * A call-off order against a contract with somebody else is how a framework agreement quietly
     * acquires spend that was never let under it.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function resolveContract(array $attributes, Vendor $vendor): ?Contract
    {
        $id = isset($attributes['contract_id']) ? (int) $attributes['contract_id'] : 0;

        if ($id <= 0) {
            return null;
        }

        $contract = Contract::query()->whereKey($id)->first();

        if ($contract === null) {
            throw new \DomainException('That contract could not be found.');
        }

        $matches = $contract->party_type === 'vendor'
            && (int) $contract->party_id === (int) $vendor->getKey();

        if (! $matches) {
            throw new \DomainException('That contract is not with '.$vendor->name.'.');
        }

        return $contract;
    }
}
