<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Services\Concerns\ResolvesParties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The contract register, both ways round.
 *
 * THE PARTY IS RESOLVED HERE, NEVER FILLED. `party_type` and `party_id` arrive from a form as two
 * strings, and a morph filled straight from a payload is the textbook §84 failure: post
 * `party_type=user` and `party_id=1` and the record now points at somebody's account, or post
 * another tenant's vendor id and the contract quietly names a company this organization cannot
 * otherwise see (§75). So the pair is looked up through the tenant-scoped query — which fails
 * closed — and only the resolved model is attached.
 *
 * DIRECTION AND PARTY MUST AGREE. A client-side contract points at a Client and a vendor-side one
 * at a Vendor. That is enforced rather than assumed, because the alternative is a register where
 * "what do we owe our subcontractors" silently includes a receivable.
 *
 * RETENTION IS MOVED, NOT SET. `addRetention()` and `releaseRetention()` take an amount and adjust
 * the balance inside a transaction, and there is no method that assigns one. A settable balance is
 * a balance that stops reconciling with the certificates behind it the first time somebody corrects
 * a typo by overwriting the total.
 *
 * WHAT IS DELIBERATELY ABSENT: any arithmetic tying `value` to what has been committed or certified
 * against it. Those numbers come from purchase orders and payments, which are parts B and E, and a
 * half-built version now would be a number on a screen that nobody could reconcile.
 */
class ContractService extends RegisterService
{
    use ResolvesParties;

    protected function entityType(): string
    {
        return 'contract';
    }

    protected function modelClass(): string
    {
        return Contract::class;
    }

    /**
     * The morph pair is protected so `fill()` can never touch it — see the class docblock.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'party_type', 'party_id', 'retention_held'];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Contract $record */
        if (blank($record->direction)) {
            $record->direction = 'client';
        }

        $record->owner_id = $attributes['owner_id'] ?? $actor->getKey();
        $record->retention_held = 0;

        $this->attachParty($record, $attributes);
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Contract $record */
        // Re-pointing a contract at a different counterparty is an ordinary edit — a contract
        // entered against the wrong subsidiary is a real and common mistake — but it goes through
        // the same resolution as creation rather than through fill().
        if (array_key_exists('party_id', $attributes) || array_key_exists('party_type', $attributes)) {
            $this->attachParty($record, $attributes);
        }

        return parent::update($record, $attributes, $actor);
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Contract $record */
        return $actor->name.' registered '.$this->label($record).' with '.$record->partyLabel().'.';
    }

    // ================================================================== retention

    /**
     * Hold back another slice.
     *
     * @param  float  $amount  positive; the direction of travel is the method, not the sign
     */
    public function addRetention(Contract $contract, float $amount, User $actor, ?string $note = null): Contract
    {
        if ($amount <= 0) {
            throw new \DomainException('A retention amount must be greater than zero.');
        }

        return DB::transaction(function () use ($contract, $amount, $actor, $note) {
            $contract->forceFill([
                'retention_held' => round((float) $contract->retention_held + $amount, 2),
            ]);

            $contract->save();

            $this->activity->record(
                $contract,
                'retention_held',
                $actor->name.' recorded retention held against '.$contract->number.'.',
                array_filter(['amount' => $amount, 'balance' => $contract->retention_held, 'note' => $note]),
                $actor,
                $this->projectIdOf($contract),
            );

            return $contract;
        });
    }

    /**
     * Release some of it back.
     *
     * Refuses to release more than is held. Allowing the balance to go negative would let a release
     * against the wrong contract look like a legitimate credit on the one it landed on.
     */
    public function releaseRetention(Contract $contract, float $amount, User $actor, ?string $note = null): Contract
    {
        if ($amount <= 0) {
            throw new \DomainException('A release amount must be greater than zero.');
        }

        if ($amount > (float) $contract->retention_held) {
            throw new \DomainException(
                'Only '.number_format((float) $contract->retention_held, 2).' is held against '
                    .$contract->number.'.'
            );
        }

        return DB::transaction(function () use ($contract, $amount, $actor, $note) {
            $contract->forceFill([
                'retention_held' => round((float) $contract->retention_held - $amount, 2),
            ]);

            $contract->save();

            $this->activity->record(
                $contract,
                'retention_released',
                $actor->name.' released retention against '.$contract->number.'.',
                array_filter(['amount' => $amount, 'balance' => $contract->retention_held, 'note' => $note]),
                $actor,
                $this->projectIdOf($contract),
            );

            return $contract;
        });
    }

    // ================================================================== the party

    /**
     * Resolve `party_type` + `party_id` into a real record of this tenant, then check the side.
     *
     * The lookup, the whitelist and the fail-closed tenant scoping live in ResolvesParties, which
     * the RFI, submittal and transmittal registers share — see that trait for why they are one
     * piece of code. What is CONTRACT-specific is the sentence after it: the resolved class has to
     * match the direction, or a register answering "what do we owe our subcontractors" silently
     * contains a receivable.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function attachParty(Contract $contract, array $attributes): void
    {
        $party = $this->resolveParty($contract, $attributes);

        if ($party === null) {
            return;
        }

        $direction = $attributes['direction'] ?? $contract->direction ?? 'client';

        if (($direction === 'client') !== ($party instanceof Client)) {
            throw new \DomainException(
                $direction === 'client'
                    ? 'A client-side contract must name a client.'
                    : 'A vendor-side contract must name a vendor.'
            );
        }

        // The typed name is kept alongside the link, not replaced by it. A contract names the legal
        // entity as it appeared on the paper, and the register row may be renamed afterwards when
        // a company rebrands or is acquired — at which point the contract would silently start
        // claiming it was signed with a company that did not exist on the day.
        $this->nameParty($contract, $party, 'party_name', $attributes);
    }

    protected function partyTypeMessage(): string
    {
        return 'A contract is held with a client or a vendor.';
    }
}
