<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Models\PettyCashAccount;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The only way cash moves in or out of a tin.
 *
 * EVERY MOVEMENT LOCKS THE ACCOUNT FIRST, reads the balance from the locked row, writes the ledger
 * row with the balance it leaves, and updates the cached balance — in one transaction. Two payouts
 * racing for the last 5,000 in the tin therefore queue, and the second is refused.
 *
 * A MISTAKE IS REVERSED, NEVER EDITED. The reversal is its own voucher, pointing at the one it
 * cancels, and a payout's reversal takes its cost back off the project.
 */
class PettyCashService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly ProjectCostService $costs,
    ) {}

    public function createAccount(array $attributes, User $actor): PettyCashAccount
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $account = new PettyCashAccount($this->accountAttributes($attributes));
            $account->organization_id = $actor->organization_id;
            $account->currency = (string) $actor->organization?->currency;
            $account->balance = '0';
            $account->created_by = $actor->id;
            $account->updated_by = $actor->id;
            $account->save();
            $this->activity->record($account, 'created', $actor->name.' opened the petty cash account '.$account->displayName().'.', [], $actor, $account->project_id);

            return $account;
        });
    }

    public function updateAccount(PettyCashAccount $account, array $attributes, User $actor): PettyCashAccount
    {
        return DB::transaction(function () use ($account, $attributes, $actor) {
            $before = $account->custodian_id;
            $account->fill($this->accountAttributes($attributes));
            $account->updated_by = $actor->id;
            $changedCustodian = $account->isDirty('custodian_id');
            $account->save();
            $this->activity->record(
                $account,
                'updated',
                $actor->name.' updated '.$account->displayName().'.',
                $changedCustodian ? ['custodian_from' => $before, 'custodian_to' => $account->custodian_id] : [],
                $actor,
                $account->project_id,
            );

            return $account;
        });
    }

    public function replenish(PettyCashAccount $account, array $data, User $actor): PettyCashTransaction
    {
        $amount = $this->positive($data['amount'] ?? null);

        return DB::transaction(function () use ($account, $data, $actor, $amount) {
            $locked = $this->lock($account);
            $after = BigDecimal::of($locked->balance)->plus($amount);
            $float = BigDecimal::of($locked->float_amount ?? '0');

            if ($float->isPositive() && $after->isGreaterThan($float)) {
                throw new \DomainException('That would take the tin past its float of '.$float.'. The most it can take is '.$locked->headroom().'.');
            }

            return $this->write($locked, 'replenishment', $amount, $after, $data, $actor, null);
        });
    }

    public function disburse(PettyCashAccount $account, array $data, User $actor): PettyCashTransaction
    {
        $amount = $this->positive($data['amount'] ?? null);
        $limit = trim($this->settings->string('petty_cash.single_disbursement_limit', ''));

        if ($limit !== '' && is_numeric($limit) && $amount->isGreaterThan(BigDecimal::of($limit))) {
            throw new \DomainException('Payouts above '.$limit.' have to go through an expense claim.');
        }

        return DB::transaction(function () use ($account, $data, $actor, $amount) {
            $locked = $this->lock($account);
            $after = BigDecimal::of($locked->balance)->minus($amount);

            if ($after->isNegative()) {
                throw new \DomainException('There is only '.$locked->balance.' in the tin.');
            }

            $transaction = $this->write($locked, 'disbursement', $amount->negated(), $after, $data, $actor, null);
            $this->costs->postPettyCash($transaction, $actor);

            return $transaction;
        });
    }

    public function reverse(PettyCashTransaction $original, string $reason, User $actor): PettyCashTransaction
    {
        if (blank($reason)) {
            throw new \DomainException('Say why the voucher is being reversed.');
        }

        return DB::transaction(function () use ($original, $reason, $actor) {
            $locked = $this->lock($original->account);
            $original = PettyCashTransaction::query()->lockForUpdate()->findOrFail($original->getKey());

            if (! $original->isReversible()) {
                throw new \DomainException('That voucher has already been reversed, or is itself a reversal.');
            }

            $amount = BigDecimal::of($original->amount)->negated();
            $after = BigDecimal::of($locked->balance)->plus($amount);

            if ($after->isNegative()) {
                throw new \DomainException('Reversing that top-up would leave the tin below zero. Reverse the payouts made from it first.');
            }

            $reversal = $this->write($locked, 'reversal', $amount, $after, [
                'transacted_on' => now()->toDateString(),
                'description' => 'Reversal of '.$original->number.': '.$reason,
                'category' => $original->category,
                'payee_name' => $original->payee_name,
                'project_id' => $original->project_id,
                'org_unit_id' => $original->org_unit_id,
            ], $actor, $original);

            $this->costs->postPettyCash($reversal, $actor);

            return $reversal;
        });
    }

    private function write(
        PettyCashAccount $account,
        string $type,
        BigDecimal $amount,
        BigDecimal $after,
        array $data,
        User $actor,
        ?PettyCashTransaction $reverses,
    ): PettyCashTransaction {
        $transaction = new PettyCashTransaction([
            'petty_cash_account_id' => $account->getKey(),
            'number' => $this->numbers->next('petty_cash_transaction'),
            'type' => $type,
            'amount' => (string) $amount->toScale(2, RoundingMode::HALF_UP),
            'balance_after' => (string) $after->toScale(2, RoundingMode::HALF_UP),
            'transacted_on' => $data['transacted_on'] ?? now()->toDateString(),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 500),
            'category' => $type === 'replenishment' ? null : ($data['category'] ?? null),
            'payee_name' => $data['payee_name'] ?? null,
            'receipt_reference' => $data['receipt_reference'] ?? null,
            'project_id' => $data['project_id'] ?? $account->project_id,
            'org_unit_id' => $data['org_unit_id'] ?? $account->org_unit_id,
            'reversal_of_id' => $reverses?->getKey(),
            'created_by' => $actor->getKey(),
        ]);
        $transaction->organization_id = $actor->organization_id;
        $transaction->save();
        $transaction->setRelation('account', $account);
        $transaction->setRelation('reversalOf', $reverses);

        $account->forceFill(['balance' => (string) $after->toScale(2, RoundingMode::HALF_UP), 'updated_by' => $actor->id])->save();

        $verb = ['replenishment' => 'topped up', 'disbursement' => 'paid out of', 'reversal' => 'reversed a voucher on'][$type];
        $this->activity->record(
            $account,
            $type,
            $actor->name.' '.$verb.' '.$account->displayName().' ('.$transaction->number.').',
            array_filter(['voucher' => $transaction->number, 'amount' => (string) $amount, 'balance' => (string) $after, 'reverses' => $reverses?->number]),
            $actor,
            $transaction->project_id === null ? null : (int) $transaction->project_id,
        );

        return $transaction;
    }

    private function lock(PettyCashAccount $account): PettyCashAccount
    {
        $locked = PettyCashAccount::query()->lockForUpdate()->findOrFail($account->getKey());

        if (! $locked->is_active) {
            throw new \DomainException('This petty cash account is closed.');
        }

        return $locked;
    }

    private function positive(mixed $value): BigDecimal
    {
        $amount = BigDecimal::of(is_numeric($value) ? (string) $value : '0')->toScale(2, RoundingMode::HALF_UP);

        if (! $amount->isPositive()) {
            throw new \DomainException('The amount has to be more than nothing.');
        }

        return $amount;
    }

    private function accountAttributes(array $attributes): array
    {
        return [
            'code' => mb_strtoupper(trim((string) ($attributes['code'] ?? ''))),
            'name' => trim((string) ($attributes['name'] ?? '')),
            'custodian_id' => $attributes['custodian_id'] ?? null,
            'org_unit_id' => $attributes['org_unit_id'] ?? null,
            'project_id' => $attributes['project_id'] ?? null,
            'float_amount' => (string) BigDecimal::of(is_numeric($attributes['float_amount'] ?? null) ? (string) $attributes['float_amount'] : '0')->toScale(2, RoundingMode::HALF_UP),
            'is_active' => (bool) ($attributes['is_active'] ?? false),
            'confidentiality' => $attributes['confidentiality'] ?? 'internal',
            'notes' => $attributes['notes'] ?? null,
        ];
    }
}
