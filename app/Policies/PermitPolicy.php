<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission to do something dangerous.
 *
 * THE OLDEST RULE IN PERMIT-TO-WORK, AND THE ONE NO CONFIGURATION RELAXES: the person who wants the
 * work done is never the person who authorises it. `issue()` refuses the requester at any scope,
 * with any permission, under any workflow. Everything else in this application is configurable
 * because companies genuinely differ; this is not, because the companies that differ on it are the
 * ones the rule was written after.
 *
 * ACCEPTING IS ALSO NOT ISSUING. The issuer may not accept their own permit either. Issuing says
 * "the area is safe for this work under these precautions"; accepting says "I have read that and my
 * crew will work within it". One person doing both means nobody checked anybody.
 *
 * AN ISSUED PERMIT IS FROZEN. Not merely a closed one — issued. The precautions are what the issuer
 * authorised against and what the accepting supervisor briefed; changing the location, the equipment
 * or the validity window afterwards would mean work proceeding under a document nobody signed. A
 * changed job is a new permit, and on a real site that is exactly what happens.
 *
 * WHAT REMAINS POSSIBLE AFTER ISSUE is the permit's actual life: accept, suspend, resume, close,
 * cancel. Those are state transitions with their own authorities, not edits.
 *
 * EXPIRY IS THE CLOCK'S JOB, NOT THE POLICY'S. `hasExpired()` decides whether work may proceed;
 * this class decides who may write. Closing an expired permit must stay possible — it is precisely
 * what an expired permit needs — so no ability here is gated on the window.
 */
class PermitPolicy extends ScopedPolicy
{
    protected string $module = 'permit';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Permit && $record->isIssued()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Permit && $record->isIssued()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Put the permit forward for issue. */
    public function submit(User $user, Permit $permit): bool
    {
        return ! $permit->isIssued() && $this->check($user, 'submit', $permit);
    }

    /**
     * Authorise the work.
     *
     * Three refusals, in order of how badly each would matter:
     *
     *   1. Not your own request. The rule in the class docblock, and it is absolute.
     *   2. Not twice — an issued permit is issued.
     *   3. Not until every required precaution is confirmed. A permit issued with the gas test
     *      unticked is a piece of paper that says the opposite of the truth, and the checklist is
     *      the only part of the document that actually does anything.
     */
    public function issue(User $user, Permit $permit): bool
    {
        if ($permit->isIssued() || $permit->isClosedOut()) {
            return false;
        }

        if ($this->requestedIt($user, $permit)) {
            return false;
        }

        if (! $permit->requiredPrecautionsConfirmed()) {
            return false;
        }

        return $this->check($user, 'approve', $permit);
    }

    /** Refuse it. `reject` rather than `approve`, and equally never your own request. */
    public function reject(User $user, Permit $permit): bool
    {
        if ($permit->isIssued() || $permit->isClosedOut()) {
            return false;
        }

        if ($this->requestedIt($user, $permit)) {
            return false;
        }

        return $this->check($user, 'reject', $permit);
    }

    /**
     * Take the permit on as the performing supervisor.
     *
     * Requires it to be issued — there is nothing to accept before then — and refuses the issuer.
     * `review` is the permission, because accepting is the receiving party's check of the
     * authorisation rather than an authorisation of its own.
     */
    public function accept(User $user, Permit $permit): bool
    {
        if (! $permit->isIssued() || $permit->isAccepted() || $permit->isClosedOut()) {
            return false;
        }

        if ((int) $permit->issued_by === $user->getKey()) {
            return false;
        }

        return $this->check($user, 'review', $permit);
    }

    /**
     * Stop work under this permit without ending it.
     *
     * Deliberately the widest authority in the module — `permit.view` reach plus nothing more would
     * be too wide, but `edit` is enough, and it is intentionally NOT `approve`. Anybody close enough
     * to the work to see it going wrong should be able to stop it; requiring the issuer's authority
     * to suspend would mean work continuing while somebody goes looking for them.
     */
    public function suspend(User $user, Permit $permit): bool
    {
        return $permit->isIssued()
            && ! $permit->isSuspended()
            && ! $permit->isClosedOut()
            && $this->check($user, 'update', $permit);
    }

    /**
     * Resume after a suspension.
     *
     * Asymmetric with suspend on purpose: stopping is easy, restarting is an authorisation. Whoever
     * could have issued the permit can restart it, and nobody else — because deciding the condition
     * that caused the stoppage has been resolved is the same judgement as issuing in the first
     * place.
     */
    public function resume(User $user, Permit $permit): bool
    {
        return $permit->isSuspended() && $this->check($user, 'approve', $permit);
    }

    /** Hand the area back. Available to the requester, the acceptor, or whoever runs the register. */
    public function close(User $user, Permit $permit): bool
    {
        return ! $permit->isClosedOut() && $this->check($user, 'close', $permit);
    }

    /** Withdraw a permit that was never used. Same authority as closing. */
    public function cancel(User $user, Permit $permit): bool
    {
        return ! $permit->isClosedOut() && $this->check($user, 'close', $permit);
    }

    /**
     * Whoever asked for the work.
     *
     * Both columns, because they diverge: a supervisor asks and a clerk raises the paperwork. Either
     * of them issuing it is the self-authorisation the whole system exists to prevent.
     */
    private function requestedIt(User $user, Permit $permit): bool
    {
        return (int) $permit->requested_by === $user->getKey()
            || (int) $permit->created_by === $user->getKey();
    }
}
