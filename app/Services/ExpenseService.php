<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\ExpenseDecided;
use App\Events\ExpenseSubmitted;
use App\Models\Expense;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Concerns\SeparatesDuties;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Expense claims: raised, submitted, decided, and — on approval — costed.
 *
 * TOTALS ARE NEVER TAKEN FROM THE FORM. Tax and total are worked out here from the net amount and
 * the tax code, and the rate and recoverability are copied onto the claim at that moment (§84, §47).
 *
 * THE APPROVER IS NOT THE CLAIMANT. Nor the person who typed the claim in for them: either could
 * otherwise approve money into their own pocket. The company can relax the rule, and the approval
 * then says so in its history.
 */
class ExpenseService extends RegisterService
{
    use SeparatesDuties;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly ProjectCostService $costs,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'expense';
    }

    protected function modelClass(): string
    {
        return Expense::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'currency', 'tax_rate', 'tax_recoverable', 'tax_amount', 'total_amount', 'paid_amount',
            'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
            'rejected_by', 'rejected_at', 'decision_reason',
        ];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Expense $record */
        $record->currency = $actor->organization?->currency;
        $record->claimant_id ??= $actor->getKey();
        $record->paid_amount = '0';
        $this->applyTax($record);
    }

    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Expense $record */
        if ($record->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft expense can be changed.');
        }

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->applyTax($record);

            return parent::update($record, [], $actor);
        });
    }

    public function submit(Expense $expense, User $actor): Expense
    {
        if ($expense->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft expense can be submitted.');
        }

        if (! BigDecimal::of($expense->total_amount ?? '0')->isPositive()) {
            throw new \DomainException('An expense has to be for more than nothing.');
        }

        return DB::transaction(function () use ($expense, $actor) {
            $expense->forceFill(['submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'decision_reason' => null]);
            $this->moveTo($expense, 'submitted');
            $expense->save();
            $this->activity->record(
                $expense,
                'submitted',
                $actor->name.' submitted '.$this->label($expense).' for approval.',
                [],
                $actor,
                $this->projectIdOf($expense),
            );
            ExpenseSubmitted::dispatch($expense, (int) $actor->getKey());

            return $expense;
        });
    }

    public function approve(Expense $expense, User $actor, ?string $comment = null): Expense
    {
        $this->assertAwaitingDecision($expense);
        $waived = $this->separate($expense, $actor);

        return DB::transaction(function () use ($expense, $actor, $comment, $waived) {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->getKey());
            if ($locked->statusKey() !== 'submitted') {
                throw new \DomainException('This expense has already been decided.');
            }

            $expense->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'decision_reason' => $comment,
            ]);
            $this->moveTo($expense, 'approved');
            $expense->save();

            $cost = $this->costs->postExpense($expense, $actor);

            $this->activity->record(
                $expense,
                'approved',
                $actor->name.' approved '.$this->label($expense).'.',
                array_filter([
                    'comment' => $comment,
                    'project_cost' => $cost?->number,
                    'self_approved' => $waived ?: null,
                ]),
                $actor,
                $this->projectIdOf($expense),
            );
            ExpenseDecided::dispatch($expense, (int) $actor->getKey());

            return $expense;
        });
    }

    public function returnForAmendment(Expense $expense, User $actor, string $reason): Expense
    {
        return $this->decideAgainst($expense, $actor, $reason, 'draft', 'returned');
    }

    public function reject(Expense $expense, User $actor, string $reason): Expense
    {
        return $this->decideAgainst($expense, $actor, $reason, 'rejected', 'rejected');
    }

    /**
     * Record money paid against the claim. Called by PaymentService inside its own transaction.
     */
    public function recordPayment(Expense $expense, string $amount, User $actor): Expense
    {
        $locked = Expense::query()->lockForUpdate()->findOrFail($expense->getKey());
        $paid = BigDecimal::of($locked->paid_amount ?? '0')->plus($amount)->toScale(2, RoundingMode::HALF_UP);

        if ($paid->isGreaterThan(BigDecimal::of($locked->total_amount))) {
            throw new \DomainException('That would pay '.$locked->number.' more than it is for.');
        }

        $locked->paid_amount = (string) $paid;
        if ($paid->isEqualTo(BigDecimal::of($locked->total_amount))) {
            $this->moveTo($locked, 'paid');
        }
        $locked->save();

        return $locked;
    }

    private function decideAgainst(Expense $expense, User $actor, string $reason, string $to, string $verb): Expense
    {
        $this->assertAwaitingDecision($expense);

        if (blank($reason)) {
            throw new \DomainException('Say why, so the claimant knows what to do next.');
        }

        $waived = $this->separate($expense, $actor);

        return DB::transaction(function () use ($expense, $actor, $reason, $to, $verb, $waived) {
            $expense->forceFill([
                'rejected_by' => $to === 'rejected' ? $actor->getKey() : null,
                'rejected_at' => $to === 'rejected' ? now() : null,
                'approved_by' => null,
                'approved_at' => null,
                'decision_reason' => $reason,
            ]);
            if ($to === 'draft') {
                $expense->forceFill(['submitted_by' => null, 'submitted_at' => null]);
            }
            $this->moveTo($expense, $to);
            $expense->save();
            $this->activity->record(
                $expense,
                $verb,
                $actor->name.' '.$verb.' '.$this->label($expense).'.',
                array_filter(['reason' => $reason, 'self_decided' => $waived ?: null]),
                $actor,
                $this->projectIdOf($expense),
            );
            ExpenseDecided::dispatch($expense, (int) $actor->getKey());

            return $expense;
        });
    }

    private function assertAwaitingDecision(Expense $expense): void
    {
        if ($expense->statusKey() !== 'submitted') {
            throw new \DomainException('Only a submitted expense can be decided.');
        }
    }

    private function separate(Expense $expense, User $actor): bool
    {
        return $this->assertSeparateDuties(
            [$expense->claimant_id, $expense->created_by, $expense->submitted_by],
            $actor,
            'finance.separate_claimant_and_approver',
            'You raised or claimed this expense, so somebody else has to decide on it.',
        );
    }

    /** Copy the tax code's rate and recoverability onto the claim and work the totals out. */
    private function applyTax(Expense $expense): void
    {
        $code = $expense->tax_code_id === null ? null : TaxCode::query()->find($expense->tax_code_id);

        if ($expense->tax_code_id !== null && $code === null) {
            throw new \DomainException('That tax code could not be found.');
        }

        $net = BigDecimal::of($expense->amount ?? '0')->toScale(2, RoundingMode::HALF_UP);
        $expense->amount = (string) $net;
        $expense->tax_rate = $code === null ? '0' : (string) $code->rate;
        $expense->tax_recoverable = (bool) $code?->is_recoverable;
        $expense->tax_amount = TaxCode::taxOn($net, $expense->tax_rate);
        $expense->total_amount = (string) $net->plus($expense->tax_amount);
    }
}
