<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PermitIssued;
use App\Events\PermitSuspended;
use App\Models\Permit;
use App\Models\PermitPrecaution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Permission to do something dangerous, for a stated period.
 *
 * THE PRECAUTION LIST IS BUILT FROM THE TYPE'S TEMPLATE AT CREATION AND THEN BELONGS TO THE PERMIT.
 * Copied, not referenced — because a permit issued in March must still show the checks that were
 * required in March even if somebody edits the confined-space template in June. A referenced
 * template would silently rewrite history on every permit ever issued against it, which is the exact
 * opposite of what a permit register is for.
 *
 * EVERY SEPARATION RULE IS ENFORCED TWICE, in the policy and again here. The policy guards HTTP; the
 * service guards every caller — a workflow auto-action, an import, a console command. The oldest
 * rule in permit-to-work is that the person who wants the work done never authorises it, and a rule
 * that holds only on the route somebody remembered to protect is not a rule.
 *
 * THE GAS TEST IS A PRECONDITION, NOT A FIELD. When the type requires one, `issue()` refuses without
 * a result. A permit issued with the gas test blank is a document that says the opposite of the
 * truth, and confined-space fatalities are overwhelmingly documented ones.
 */
class PermitService extends RegisterService
{
    protected function entityType(): string
    {
        return 'permit';
    }

    protected function modelClass(): string
    {
        return Permit::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'requested_by',
            'issued_at', 'issued_by',
            'accepted_at', 'accepted_by',
            'suspended_at', 'suspended_by', 'suspension_reason',
            'closed_at', 'closed_by', 'closure_notes',
            'cancelled_at', 'cancelled_by', 'cancellation_reason',
            'gas_test_result', 'gas_tested_at', 'gas_tested_by',
        ];
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Permit $record */
        $record->requested_by = $actor->getKey();

        $type = $record->permit_type_id !== null ? $record->permitType()->first() : null;

        if ($type !== null) {
            // The type's requirements are copied onto the permit, so the permit remains readable on
            // its own terms after the type has been edited or deactivated.
            $record->gas_test_required = (bool) $type->requires_gas_test;

            if ($record->valid_from !== null && $record->valid_to === null && $type->default_duration_hours) {
                $record->valid_to = $record->valid_from->copy()->addHours((int) $type->default_duration_hours);
            }
        }
    }

    /** @param array<string,mixed> $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Permit $record */
        $type = $record->permit_type_id !== null ? $record->permitType()->first() : null;

        if ($type === null) {
            return;
        }

        foreach ($type->defaultPrecautions() as $index => $precaution) {
            $line = new PermitPrecaution;
            $line->organization_id = $record->organization_id;
            $line->permit_id = $record->getKey();
            $line->sort_order = $index;
            $line->description = $precaution['description'];
            $line->is_required = $precaution['is_required'];
            $line->save();
        }
    }

    /** Tick a precaution, having actually checked the thing. */
    public function confirmPrecaution(PermitPrecaution $precaution, User $actor, ?string $comment = null): PermitPrecaution
    {
        $precaution->forceFill([
            'is_confirmed' => true,
            'confirmed_by' => $actor->getKey(),
            'confirmed_at' => now(),
            'comment' => $comment ?? $precaution->comment,
        ]);

        $precaution->save();

        return $precaution;
    }

    /** Record the atmosphere test. Separate from issuing, because it is done by a different person. */
    public function recordGasTest(Permit $permit, User $actor, string $result): Permit
    {
        if ($permit->isIssued()) {
            throw new \DomainException($permit->number.' has already been issued.');
        }

        if (blank($result)) {
            throw new \DomainException('A gas test must record a result.');
        }

        return DB::transaction(function () use ($permit, $actor, $result) {
            $permit->forceFill([
                'gas_test_result' => $result,
                'gas_tested_at' => now(),
                'gas_tested_by' => $actor->getKey(),
            ]);

            $permit->save();

            $this->activity->record(
                $permit,
                'gas_tested',
                $actor->name.' recorded a gas test on '.$this->label($permit).': '.$result,
                ['result' => $result],
                $actor,
                $this->projectIdOf($permit),
            );

            return $permit;
        });
    }

    /**
     * Authorise the work.
     *
     * Four refusals, and none of them is a formality: not twice, not your own request, not with a
     * required precaution unticked, not with the gas test outstanding when the type demands one.
     */
    public function issue(Permit $permit, User $actor): Permit
    {
        if ($permit->isIssued()) {
            throw new \DomainException($permit->number.' has already been issued.');
        }

        if ((int) $permit->requested_by === (int) $actor->getKey()
            || (int) $permit->created_by === (int) $actor->getKey()) {
            throw new \DomainException('A permit cannot be issued by the person who requested it.');
        }

        if (! $permit->requiredPrecautionsConfirmed()) {
            throw new \DomainException(
                $permit->number.' still has required precautions that have not been confirmed.'
            );
        }

        if ($permit->gas_test_required && blank($permit->gas_test_result)) {
            throw new \DomainException($permit->number.' requires a gas test before it can be issued.');
        }

        return DB::transaction(function () use ($permit, $actor) {
            $permit->forceFill([
                'issued_at' => now(),
                'issued_by' => $actor->getKey(),
            ]);

            $this->moveTo($permit, 'issued');
            $permit->save();

            $this->activity->record(
                $permit,
                'issued',
                $actor->name.' issued '.$this->label($permit).'.',
                array_filter([
                    'valid_to' => $permit->valid_to?->toDateTimeString(),
                    'gas_test_result' => $permit->gas_test_result,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($permit),
            );

            PermitIssued::dispatch($permit, (int) $actor->getKey());

            return $permit;
        });
    }

    /** Take the permit on as the performing supervisor. Never the issuer. */
    public function accept(Permit $permit, User $actor): Permit
    {
        if (! $permit->isIssued()) {
            throw new \DomainException($permit->number.' has not been issued yet.');
        }

        if ($permit->isAccepted()) {
            throw new \DomainException($permit->number.' has already been accepted.');
        }

        if ((int) $permit->issued_by === (int) $actor->getKey()) {
            throw new \DomainException('A permit cannot be accepted by the person who issued it.');
        }

        return DB::transaction(function () use ($permit, $actor) {
            $permit->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $actor->getKey(),
            ]);

            $this->moveTo($permit, 'active');
            $permit->save();

            $this->activity->record(
                $permit,
                'accepted',
                $actor->name.' accepted '.$this->label($permit).'.',
                [],
                $actor,
                $this->projectIdOf($permit),
            );

            return $permit;
        });
    }

    /** Stop work without ending the permit. A reason is required — it is the whole record. */
    public function suspend(Permit $permit, User $actor, string $reason): Permit
    {
        if (! $permit->isIssued() || $permit->isClosedOut()) {
            throw new \DomainException($permit->number.' is not live and cannot be suspended.');
        }

        if (blank($reason)) {
            throw new \DomainException('Suspending a permit must say why.');
        }

        return DB::transaction(function () use ($permit, $actor, $reason) {
            $permit->forceFill([
                'suspended_at' => now(),
                'suspended_by' => $actor->getKey(),
                'suspension_reason' => $reason,
            ]);

            $this->moveTo($permit, 'suspended');
            $permit->save();

            $this->activity->record(
                $permit,
                'suspended',
                $actor->name.' suspended '.$this->label($permit).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($permit),
            );

            PermitSuspended::dispatch($permit, (int) $actor->getKey());

            return $permit;
        });
    }

    /** Restart after a suspension. Asymmetric with suspend on purpose — see PermitPolicy. */
    public function resume(Permit $permit, User $actor): Permit
    {
        if (! $permit->isSuspended()) {
            throw new \DomainException($permit->number.' is not suspended.');
        }

        return DB::transaction(function () use ($permit, $actor) {
            $permit->forceFill([
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ]);

            $this->moveTo($permit, 'active');
            $permit->save();

            $this->activity->record(
                $permit,
                'resumed',
                $actor->name.' resumed work under '.$this->label($permit).'.',
                [],
                $actor,
                $this->projectIdOf($permit),
            );

            return $permit;
        });
    }

    /** Hand the area back. Deliberately possible on an expired permit — that is what expiry needs. */
    public function close(Permit $permit, User $actor, ?string $notes = null): Permit
    {
        if ($permit->isClosedOut()) {
            throw new \DomainException($permit->number.' is already closed.');
        }

        return DB::transaction(function () use ($permit, $actor, $notes) {
            $permit->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'closure_notes' => $notes,
            ]);

            $this->moveTo($permit, 'closed');
            $permit->save();

            $this->activity->record(
                $permit,
                'closed',
                $actor->name.' closed '.$this->label($permit).'.',
                [],
                $actor,
                $this->projectIdOf($permit),
            );

            return $permit;
        });
    }

    /**
     * Refuse to authorise the work.
     *
     * NEVER YOUR OWN REQUEST, exactly as with issuing. The rule is not about protecting the issuer's
     * judgement, it is about there being two people involved at all, and it holds in both
     * directions: an approver who cannot issue their own permit must not be able to dispose of it by
     * refusing it either.
     *
     * REJECTION WRITES THE SAME THREE COLUMNS AS CANCELLATION, and the status is what tells them
     * apart. Both are "this permit was withdrawn without ever being issued, and here is why", so
     * both need `isClosedOut()` to become true and both need the reason kept; adding a parallel set
     * of rejection columns would mean every predicate in the module had to check two things instead
     * of one, and the first one somebody forgot would leave a rejected permit looking live. The
     * status vocabulary carries the distinction that reporting actually needs — "refused" and
     * "withdrawn by the requester" are different numbers to a safety manager.
     */
    public function reject(Permit $permit, User $actor, string $reason): Permit
    {
        if ($permit->isIssued()) {
            throw new \DomainException($permit->number.' has already been issued.');
        }

        if ($permit->isClosedOut()) {
            throw new \DomainException($permit->number.' is already closed.');
        }

        if ((int) $permit->requested_by === (int) $actor->getKey()
            || (int) $permit->created_by === (int) $actor->getKey()) {
            throw new \DomainException('A permit cannot be rejected by the person who requested it.');
        }

        if (blank($reason)) {
            throw new \DomainException('Refusing a permit must say why.');
        }

        return DB::transaction(function () use ($permit, $actor, $reason) {
            $permit->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ]);

            $this->moveTo($permit, 'rejected');
            $permit->save();

            $this->activity->record(
                $permit,
                'rejected',
                $actor->name.' refused '.$this->label($permit).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($permit),
            );

            return $permit;
        });
    }

    /** Withdraw a permit that was never used. */
    public function cancel(Permit $permit, User $actor, string $reason): Permit
    {
        if ($permit->isClosedOut()) {
            throw new \DomainException($permit->number.' is already closed.');
        }

        if (blank($reason)) {
            throw new \DomainException('Cancelling a permit must say why.');
        }

        return DB::transaction(function () use ($permit, $actor, $reason) {
            $permit->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ]);

            $this->moveTo($permit, 'cancelled');
            $permit->save();

            $this->activity->record(
                $permit,
                'cancelled',
                $actor->name.' cancelled '.$this->label($permit).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($permit),
            );

            return $permit;
        });
    }
}
