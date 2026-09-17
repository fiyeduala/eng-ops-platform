<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Income;
use App\Models\TaxCode;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Income: raised as a draft, issued to the client, then received through incoming payments.
 *
 * `part_received` and `received` are never chosen by a person. They follow from the payments
 * recorded against the income, so the register cannot say "received" about money nobody logged.
 */
class IncomeService extends RegisterService
{
    protected function entityType(): string
    {
        return 'income';
    }

    protected function modelClass(): string
    {
        return Income::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'currency', 'tax_rate', 'tax_amount', 'total_amount', 'received_amount',
            'issued_by', 'issued_at', 'decision_reason',
        ];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Income $record */
        $record->currency = $actor->organization?->currency;
        $record->received_amount = '0';
        $this->applyContract($record);
        $this->applyTax($record);
    }

    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Income $record */
        if ($record->statusKey() !== 'draft') {
            throw new \DomainException('Only draft income can be changed. Cancel it and raise it again.');
        }

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->applyContract($record);
            $this->applyTax($record);

            return parent::update($record, [], $actor);
        });
    }

    public function issue(Income $income, User $actor): Income
    {
        if ($income->statusKey() !== 'draft') {
            throw new \DomainException('Only draft income can be issued.');
        }

        if (! BigDecimal::of($income->total_amount ?? '0')->isPositive()) {
            throw new \DomainException('Income has to be for more than nothing.');
        }

        return DB::transaction(function () use ($income, $actor) {
            $income->forceFill(['issued_by' => $actor->getKey(), 'issued_at' => now()]);
            $this->moveTo($income, 'issued');
            $income->save();
            $this->activity->record($income, 'issued', $actor->name.' issued '.$this->label($income).'.', [], $actor, $this->projectIdOf($income));

            return $income;
        });
    }

    public function cancel(Income $income, User $actor, string $reason): Income
    {
        if (! in_array($income->statusKey(), ['draft', 'issued'], true)) {
            throw new \DomainException('Income that has started to be received cannot be cancelled.');
        }

        if (blank($reason)) {
            throw new \DomainException('Say why the income is being cancelled.');
        }

        return DB::transaction(function () use ($income, $actor, $reason) {
            if (Income::query()->lockForUpdate()->findOrFail($income->getKey())->received_amount > 0) {
                throw new \DomainException('Income that has started to be received cannot be cancelled.');
            }
            $income->forceFill(['decision_reason' => $reason]);
            $this->moveTo($income, 'cancelled');
            $income->save();
            $this->activity->record($income, 'cancelled', $actor->name.' cancelled '.$this->label($income).'.', ['reason' => $reason], $actor, $this->projectIdOf($income));

            return $income;
        });
    }

    /** Record money received. Called by PaymentService inside its own transaction. */
    public function recordReceipt(Income $income, string $amount, User $actor): Income
    {
        $locked = Income::query()->lockForUpdate()->findOrFail($income->getKey());

        if (! $locked->isReceivable()) {
            throw new \DomainException($locked->number.' is not awaiting payment.');
        }

        $received = BigDecimal::of($locked->received_amount ?? '0')->plus($amount)->toScale(2, RoundingMode::HALF_UP);
        $total = BigDecimal::of($locked->total_amount);

        if ($received->isGreaterThan($total)) {
            throw new \DomainException('That is more than is still owed on '.$locked->number.'.');
        }

        $locked->received_amount = (string) $received;
        $this->moveTo($locked, $received->isEqualTo($total) ? 'received' : 'part_received');
        $locked->save();
        $this->activity->record(
            $locked,
            'received',
            $actor->name.' recorded '.$locked->currency.' '.number_format((float) $amount, 2).' received against '.$this->label($locked).'.',
            ['amount' => $amount, 'received' => (string) $received],
            $actor,
            $this->projectIdOf($locked),
        );

        return $locked;
    }

    /** A contract names its client and project; income raised against it must agree. */
    private function applyContract(Income $income): void
    {
        if ($income->contract_id === null) {
            return;
        }

        $contract = Contract::query()->find($income->contract_id);
        if ($contract === null) {
            throw new \DomainException('That contract could not be found.');
        }

        if ($contract->direction !== 'client') {
            throw new \DomainException('Income can only be raised against a client contract.');
        }

        $sides = [
            'client_id' => $contract->party_type === (new Client)->getMorphClass() ? $contract->party_id : null,
            'project_id' => $contract->project_id,
        ];

        foreach ($sides as $column => $theirs) {
            if ($theirs === null) {
                continue;
            }
            if ($income->getAttribute($column) !== null && (int) $income->getAttribute($column) !== (int) $theirs) {
                throw new \DomainException('The '.($column === 'client_id' ? 'client' : 'project').' has to match the contract.');
            }
            $income->setAttribute($column, $theirs);
        }
    }

    private function applyTax(Income $income): void
    {
        $code = $income->tax_code_id === null ? null : TaxCode::query()->find($income->tax_code_id);

        if ($income->tax_code_id !== null && $code === null) {
            throw new \DomainException('That tax code could not be found.');
        }

        $net = BigDecimal::of($income->amount ?? '0')->toScale(2, RoundingMode::HALF_UP);
        $income->amount = (string) $net;
        $income->tax_rate = $code === null ? '0' : (string) $code->rate;
        $income->tax_amount = TaxCode::taxOn($net, $income->tax_rate);
        $income->total_amount = (string) $net->plus($income->tax_amount);
    }
}
