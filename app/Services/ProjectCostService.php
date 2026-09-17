<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\PettyCashTransaction;
use App\Models\ProjectCost;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\FixedRateMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Post an approved expense once. Called inside expense approval's transaction.
     *
     * The category key becomes the cost type, which is what lets a budget line called "travel"
     * count approved travel claims. Recoverable tax is left out; see Expense::costAmount().
     */
    public function postExpense(Expense $expense, User $actor): ?ProjectCost
    {
        if ($this->alreadyPosted($expense)) {
            return null;
        }

        $amount = BigDecimal::of($expense->costAmount())->toScale(2, RoundingMode::HALF_UP);

        return $this->postSingle(
            source: $expense,
            costType: $expense->category,
            description: $expense->title,
            amount: $amount,
            currency: (string) $expense->currency,
            actor: $actor,
            attributes: [
                'project_id' => $expense->project_id,
                'org_unit_id' => $expense->org_unit_id,
                'vendor_id' => $expense->vendor_id,
                'confidentiality' => $this->classification($expense->confidentiality),
                'incurred_at' => $expense->expense_date ?? now(),
            ],
            narrative: $actor->name.' posted {number} from '.$expense->number.'.',
        );
    }

    /**
     * Post a petty-cash payout, or the negative of one when it is reversed.
     *
     * Replenishments are not costs — they move money from the bank into a tin — so only
     * disbursements, and reversals of disbursements, reach the ledger.
     */
    public function postPettyCash(PettyCashTransaction $transaction, User $actor): ?ProjectCost
    {
        $original = $transaction->type === 'reversal' ? $transaction->reversalOf : $transaction;

        if ($original === null || $original->type !== 'disbursement' || $this->alreadyPosted($transaction)) {
            return null;
        }

        $account = $transaction->account;
        // Disbursements are stored negative (cash out of the tin) and are a positive cost; a
        // reversal is stored positive and so posts negative. Negating the tin movement does both.
        $amount = BigDecimal::of($transaction->amount)->negated()->toScale(2, RoundingMode::HALF_UP);

        return $this->postSingle(
            source: $transaction,
            costType: $original->category ?: 'petty_cash',
            description: $transaction->description,
            amount: $amount,
            currency: (string) $account->currency,
            actor: $actor,
            attributes: [
                'project_id' => $transaction->project_id,
                'org_unit_id' => $transaction->org_unit_id,
                'vendor_id' => null,
                'confidentiality' => $this->classification($account->confidentiality),
                'incurred_at' => $transaction->transacted_on ?? now(),
            ],
            narrative: $actor->name.' posted {number} from petty cash voucher '.$transaction->number.'.',
        );
    }

    private function postSingle(
        Model $source,
        string $costType,
        string $description,
        BigDecimal $amount,
        string $currency,
        User $actor,
        array $attributes,
        string $narrative,
    ): ProjectCost {
        $baseCurrency = (string) ($actor->organization?->currency ?: $currency);

        if ($currency !== '' && $currency !== $baseCurrency) {
            throw new \DomainException('Expenses and petty cash are kept in the company currency, '.$baseCurrency.'.');
        }

        $cost = new ProjectCost([
            'number' => $this->numbers->next('project_cost'),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'cost_type' => mb_substr($costType, 0, 40),
            'description' => mb_substr($description, 0, 500),
            'quantity' => '1',
            'uom' => null,
            'unit_price' => (string) $amount,
            'currency' => $baseCurrency,
            'exchange_rate' => '1',
            'amount' => (string) $amount,
            'base_currency' => $baseCurrency,
            'base_amount' => (string) $amount,
            'created_by' => $actor->getKey(),
            ...$attributes,
        ]);
        $cost->organization_id = $actor->organization_id;
        $cost->save();

        $projectId = $cost->project_id === null ? null : (int) $cost->project_id;
        $this->activity->record(
            $cost,
            'posted',
            str_replace('{number}', $cost->number, $narrative),
            ['base_amount' => (string) $amount, 'currency' => $baseCurrency],
            $actor,
            $projectId,
        );

        return $cost;
    }

    private function alreadyPosted(Model $source): bool
    {
        return ProjectCost::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
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
