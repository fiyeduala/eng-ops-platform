<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\ProjectCost;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\FixedRateMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Posts source-backed costs into the operational finance ledger. */
class ProjectCostService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly ActivityRecorder $activity,
    ) {}

    /** Post every accepted receipt line once. Called inside receipt acceptance's transaction. */
    public function postGoodsReceipt(GoodsReceipt $receipt, User $actor): int
    {
        $receipt->loadMissing(['items.purchaseOrderItem.purchaseOrder', 'purchaseOrder']);
        $order = $receipt->purchaseOrder;

        if ($order === null) {
            throw new \DomainException('The purchase order for this delivery is missing.');
        }

        $baseCurrency = (string) $actor->organization?->currency;
        $currency = (string) ($order->currency ?: $baseCurrency);
        $rate = FixedRateMoney::exchangeRate($currency, $baseCurrency, $order->exchange_rate);

        $posted = 0;

        foreach ($receipt->items as $line) {
            if ((float) $line->quantity_accepted <= 0 || $this->alreadyPosted($line)) {
                continue;
            }

            $orderLine = $line->purchaseOrderItem;
            if ($orderLine === null) {
                throw new \DomainException('A received line no longer has its purchase-order line.');
            }

            $quantity = BigDecimal::of($line->quantity_accepted);
            $unitPrice = BigDecimal::of($orderLine->unit_price);
            $amount = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);
            $baseAmount = FixedRateMoney::toBase($amount, $currency, $rate, $baseCurrency);
            $classification = $this->classification($receipt->confidentiality, $order->confidentiality);

            $cost = new ProjectCost([
                'number' => $this->numbers->next('project_cost'),
                'source_type' => $line->getMorphClass(),
                'source_id' => $line->getKey(),
                'cost_type' => 'procurement',
                'description' => $orderLine->description,
                'quantity' => (string) $quantity,
                'uom' => $orderLine->uom,
                'unit_price' => (string) $unitPrice,
                'currency' => $currency,
                'exchange_rate' => (string) $rate,
                'amount' => (string) $amount,
                'base_currency' => $baseCurrency,
                'base_amount' => (string) $baseAmount,
                'project_id' => $order->project_id,
                'org_unit_id' => $order->org_unit_id,
                'vendor_id' => $order->vendor_id,
                'confidentiality' => $classification,
                'incurred_at' => $receipt->received_at ?? now(),
                'created_by' => $actor->getKey(),
            ]);
            $cost->organization_id = $actor->organization_id;
            $cost->save();

            $this->activity->record(
                $cost,
                'posted',
                $actor->name.' posted '.$cost->number.' from '.$receipt->number.'.',
                ['receipt' => $receipt->number, 'base_amount' => (string) $baseAmount, 'currency' => $baseCurrency],
                $actor,
                $order->project_id === null ? null : (int) $order->project_id,
            );
            $posted++;
        }

        if ($posted > 0) {
            $this->activity->record(
                $receipt,
                'costed',
                $posted.' '.($posted === 1 ? 'cost was' : 'costs were').' posted from '.$receipt->number.'.',
                ['lines' => $posted],
                $actor,
                $order->project_id === null ? null : (int) $order->project_id,
            );
        }

        return $posted;
    }

    private function alreadyPosted(GoodsReceiptItem $line): bool
    {
        return ProjectCost::query()
            ->where('source_type', $line->getMorphClass())
            ->where('source_id', $line->getKey())
            ->exists();
    }

    private function classification(mixed ...$values): Confidentiality
    {
        $current = Confidentiality::Internal;
        foreach ($values as $value) {
            $candidate = $value instanceof Confidentiality ? $value : Confidentiality::tryFrom((string) $value);
            if ($candidate !== null && $candidate->rank() > $current->rank()) {
                $current = $candidate;
            }
        }

        return $current;
    }
}
