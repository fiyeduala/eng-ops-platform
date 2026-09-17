<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\PaymentAwaitingAction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Concerns\SeparatesDuties;
use App\Support\Enums\Confidentiality;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Payments out (approve, then pay) and payments in (recorded as received).
 *
 * EVERYTHING ABOUT WHO IS PAID COMES FROM WHAT IS BEING PAID. The vendor, the client, the currency,
 * the project and the department are copied from the purchase order, expense or income, never from
 * the form, so a payment cannot be pointed at an approved order and addressed to somebody else (§84).
 *
 * NO OVERPAYMENT, CHECKED TWICE. Once when the payment is raised, so the form can say so, and again
 * under a lock on the thing being paid when money actually moves, because two payments raised
 * against the same order on the same morning were each individually within the limit.
 */
class PaymentService extends RegisterService
{
    use SeparatesDuties;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly ExpenseService $expenses,
        private readonly IncomeService $incomes,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'payment';
    }

    protected function modelClass(): string
    {
        return Payment::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'direction', 'payable_type', 'payable_id',
            'vendor_id', 'client_id', 'currency', 'project_id', 'org_unit_id',
            'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'decision_reason',
        ];
    }

    /**
     * Resolve what is being paid. Returns null for anything that is not a payable of that direction.
     */
    public function resolvePayable(string $direction, ?string $type, mixed $id): ?Model
    {
        if (! in_array($type, Payment::PAYABLES[$direction] ?? [], true) || blank($id)) {
            return null;
        }

        $class = Relation::getMorphedModel($type);

        return $class === null ? null : $class::query()->find((int) $id);
    }

    public function create(array $attributes, User $actor): Model
    {
        $direction = (string) ($attributes['direction'] ?? '');
        $payable = $this->resolvePayable($direction, $attributes['payable_type'] ?? null, $attributes['payable_id'] ?? null);

        if ($payable === null) {
            throw new \DomainException('Choose what this payment is for.');
        }

        return DB::transaction(function () use ($attributes, $actor, $direction, $payable) {
            // Locked before the balance is read, so two drafts raised at once cannot both fit.
            $payable = $payable::query()->lockForUpdate()->findOrFail($payable->getKey());
            $amount = $this->money($attributes['amount'] ?? '0');
            $this->assertWithinOutstanding($payable, $amount, null);

            /** @var Payment $payment */
            $payment = parent::create([...$attributes, 'amount' => $amount], $actor);

            if ($direction === 'incoming') {
                $this->receive($payment, $payable, $actor);
            }

            return $payment;
        });
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Payment $record */
        $direction = (string) $attributes['direction'];
        $payable = $this->resolvePayable($direction, $attributes['payable_type'], $attributes['payable_id']);

        $record->direction = $direction;
        $record->payable_type = $payable->getMorphClass();
        $record->payable_id = $payable->getKey();
        $this->copyFromPayable($record, $payable, $actor);
    }

    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Payment $record */
        if ($record->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft payment can be changed.');
        }

        return DB::transaction(function () use ($record, $attributes, $actor) {
            if (array_key_exists('amount', $attributes)) {
                $attributes['amount'] = $this->money($attributes['amount']);
                $this->assertWithinOutstanding($this->lockPayable($record), $attributes['amount'], $record);
            }

            return parent::update($record, $attributes, $actor);
        });
    }

    public function submit(Payment $payment, User $actor): Payment
    {
        $this->assertOutgoingIn($payment, 'draft', 'Only a draft payment can be submitted.');

        return DB::transaction(function () use ($payment, $actor) {
            $this->assertWithinOutstanding($this->lockPayable($payment), (string) $payment->amount, $payment);
            $payment->forceFill(['submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'decision_reason' => null]);
            $this->moveTo($payment, 'submitted');
            $payment->save();
            $this->activity->record($payment, 'submitted', $actor->name.' submitted '.$this->label($payment).' for approval.', [], $actor, $this->projectIdOf($payment));
            PaymentAwaitingAction::dispatch($payment, (int) $actor->getKey(), 'approve');

            return $payment;
        });
    }

    public function approve(Payment $payment, User $actor): Payment
    {
        $this->assertOutgoingIn($payment, 'submitted', 'Only a submitted payment can be approved.');

        return DB::transaction(function () use ($payment, $actor) {
            $this->assertWithinOutstanding($this->lockPayable($payment), (string) $payment->amount, $payment);
            $payment->forceFill(['approved_by' => $actor->getKey(), 'approved_at' => now()]);
            $this->moveTo($payment, 'approved');
            $payment->save();
            $this->activity->record($payment, 'approved', $actor->name.' approved '.$this->label($payment).'.', [], $actor, $this->projectIdOf($payment));
            PaymentAwaitingAction::dispatch($payment, (int) $actor->getKey(), 'pay');

            return $payment;
        });
    }

    public function reject(Payment $payment, User $actor, string $reason): Payment
    {
        $this->assertOutgoingIn($payment, 'submitted', 'Only a submitted payment can be rejected.');

        return $this->close($payment, $actor, $reason, 'rejected');
    }

    public function cancel(Payment $payment, User $actor, string $reason): Payment
    {
        if (! $payment->isOutgoing() || ! in_array($payment->statusKey(), ['draft', 'approved'], true)) {
            throw new \DomainException('Only a draft or approved outgoing payment can be cancelled.');
        }

        return $this->close($payment, $actor, $reason, 'cancelled');
    }

    /** Release the money. The person doing it is not the person who approved it. */
    public function pay(Payment $payment, User $actor, ?string $paidOn = null, ?string $reference = null): Payment
    {
        $this->assertOutgoingIn($payment, 'approved', 'Only an approved payment can be marked paid.');

        $waived = $this->assertSeparateDuties(
            $payment->approved_by,
            $actor,
            'finance.separate_payment_approver_and_payer',
            'You approved this payment, so somebody else has to release it.',
        );

        return DB::transaction(function () use ($payment, $actor, $paidOn, $reference, $waived) {
            $payable = $this->lockPayable($payment);
            $this->assertWithinOutstanding($payable, (string) $payment->amount, $payment);

            $payment->forceFill([
                'paid_by' => $actor->getKey(),
                'paid_at' => now(),
                'paid_on' => $paidOn ?? $payment->paid_on ?? now()->toDateString(),
                'reference' => filled($reference) ? $reference : $payment->reference,
            ]);
            $this->moveTo($payment, 'paid');
            $payment->save();

            if ($payable instanceof Expense) {
                $this->expenses->recordPayment($payable, (string) $payment->amount, $actor);
            }

            $this->activity->record(
                $payment,
                'paid',
                $actor->name.' marked '.$this->label($payment).' as paid.',
                array_filter(['self_released' => $waived ?: null]),
                $actor,
                $this->projectIdOf($payment),
            );

            return $payment;
        });
    }

    /**
     * What is still owed on a payable, less every live payment against it except `$ignore`.
     *
     * "Live" includes drafts and approvals, not only money already paid: otherwise three drafts
     * each for the whole balance would all look valid until the third was released.
     */
    public function outstandingOn(Model $payable, ?Payment $ignore = null): string
    {
        $total = match (true) {
            $payable instanceof PurchaseOrder => BigDecimal::of($payable->total ?? '0'),
            $payable instanceof Expense => BigDecimal::of($payable->total_amount ?? '0'),
            $payable instanceof Income => BigDecimal::of($payable->total_amount ?? '0'),
            default => BigDecimal::zero(),
        };

        $committed = Payment::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->when($ignore?->exists, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->statusKey(Payment::COMMITTED_STATUSES)
            ->sum('amount');

        return (string) $total->minus(BigDecimal::of((string) ($committed ?: '0')))->toScale(2, RoundingMode::HALF_UP);
    }

    public function isPayable(Model $payable): bool
    {
        return match (true) {
            $payable instanceof PurchaseOrder => in_array($payable->statusKey(), ['approved', 'issued', 'part_received', 'received', 'closed'], true),
            $payable instanceof Expense => $payable->statusKey() === 'approved',
            $payable instanceof Income => $payable->isReceivable(),
            default => false,
        };
    }

    private function receive(Payment $payment, Model $income, User $actor): void
    {
        /** @var Income $income */
        $payment->forceFill([
            'paid_by' => $actor->getKey(),
            'paid_at' => now(),
            'paid_on' => $payment->paid_on ?? now()->toDateString(),
        ]);
        $this->moveTo($payment, 'paid');
        $payment->save();
        $this->incomes->recordReceipt($income, (string) $payment->amount, $actor);
    }

    private function assertWithinOutstanding(?Model $payable, string $amount, ?Payment $ignore): void
    {
        if ($payable === null || ! $this->isPayable($payable)) {
            throw new \DomainException('That is not something that can be paid at the moment.');
        }

        $amount = BigDecimal::of($amount);
        if (! $amount->isPositive()) {
            throw new \DomainException('A payment has to be for more than nothing.');
        }

        $outstanding = BigDecimal::of($this->outstandingOn($payable, $ignore));
        if ($amount->isGreaterThan($outstanding)) {
            throw new \DomainException('That is more than is still owed. The most this payment can be is '.$outstanding.'.');
        }
    }

    private function lockPayable(Payment $payment): Model
    {
        $class = Relation::getMorphedModel($payment->payable_type);
        $payable = $class === null ? null : $class::query()->lockForUpdate()->find($payment->payable_id);

        if ($payable === null) {
            throw new \DomainException('What this payment was for no longer exists.');
        }

        return $payable;
    }

    private function copyFromPayable(Payment $payment, Model $payable, User $actor): void
    {
        $base = $actor->organization?->currency;

        match (true) {
            $payable instanceof PurchaseOrder => $payment->forceFill([
                'vendor_id' => $payable->vendor_id,
                'client_id' => null,
                'payee_name' => $payable->vendor?->name,
                'currency' => $payable->currency ?: $base,
            ]),
            $payable instanceof Expense => $payment->forceFill([
                'vendor_id' => $payable->vendor_id,
                'client_id' => null,
                'payee_name' => $payable->payeeLabel(),
                'currency' => $payable->currency ?: $base,
            ]),
            $payable instanceof Income => $payment->forceFill([
                'vendor_id' => null,
                'client_id' => $payable->client_id,
                'payee_name' => $payable->client_id === null ? null : Client::query()->find($payable->client_id)?->name,
                'currency' => $payable->currency ?: $base,
            ]),
        };

        $payment->forceFill([
            'project_id' => $payable->getAttribute('project_id'),
            'org_unit_id' => $payable->getAttribute('org_unit_id'),
        ]);

        // A payment is at least as confidential as what it pays.
        $theirs = $payable->getAttribute('confidentiality');
        $theirs = $theirs instanceof Confidentiality ? $theirs : Confidentiality::tryFrom((string) $theirs);
        $ours = $payment->confidentiality instanceof Confidentiality
            ? $payment->confidentiality
            : Confidentiality::tryFrom((string) $payment->confidentiality);

        if ($theirs !== null && ($ours === null || $theirs->rank() > $ours->rank())) {
            $payment->confidentiality = $theirs;
        }
    }

    private function close(Payment $payment, User $actor, string $reason, string $to): Payment
    {
        if (blank($reason)) {
            throw new \DomainException('Say why.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason, $to) {
            $payment->forceFill(['decision_reason' => $reason]);
            $this->moveTo($payment, $to);
            $payment->save();
            $this->activity->record($payment, $to, $actor->name.' '.$to.' '.$this->label($payment).'.', ['reason' => $reason], $actor, $this->projectIdOf($payment));

            return $payment;
        });
    }

    private function assertOutgoingIn(Payment $payment, string $status, string $message): void
    {
        if (! $payment->isOutgoing() || $payment->statusKey() !== $status) {
            throw new \DomainException($message);
        }
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HALF_UP);
    }
}
