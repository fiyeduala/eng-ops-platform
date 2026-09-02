<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may ask for something, and — separately — who may say yes.
 *
 * THE ONE RULE THIS CLASS ADDS is that a submitted request stops being editable by the person who
 * wrote it. Not because editing is dangerous in itself, but because an approver reads a request,
 * walks to a meeting and approves it an hour later: if the requester can change the quantities in
 * between, the approval is meaningless and nobody can tell afterwards that it happened. Draft is
 * the requester's; everything after that belongs to the process.
 *
 * `approve()` DELIBERATELY DOES NOT IMPLY `update()`. An approver may say yes or no and may not
 * quietly correct the thing they are approving — that is the whole content of a four-eyes control,
 * and the moment approval carries edit rights the second pair of eyes is the same pair.
 *
 * THE REQUESTER-IS-NOT-THE-APPROVER RULE IS NOT HERE. It depends on a per-organization setting and
 * on who raised this particular request, which makes it a service concern: PurchaseRequestService
 * refuses the move and says why. A policy that returned false would present as "you do not have
 * permission", which is both untrue and unhelpful to somebody who has exactly that permission and
 * simply may not use it on their own request.
 */
class PurchaseRequestPolicy extends ScopedPolicy
{
    protected string $module = 'purchase_request';

    /**
     * A draft is the requester's to change. After that it is the record of a decision.
     *
     * `purchase_request.manage` reopens it — somebody has to be able to correct a request that was
     * submitted with the wrong project — and that is a different, narrower authority than raising
     * one.
     */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof PurchaseRequest) {
            return parent::update($user, $record);
        }

        if ($record->statusKey() === 'draft') {
            return parent::update($user, $record);
        }

        return $this->check($user, 'manage', $record);
    }

    /**
     * Send it for a decision.
     *
     * Separate from `update` because the two are held by different people surprisingly often: a
     * storekeeper types up what the site foreman needs and the foreman submits it.
     */
    public function submit(User $user, PurchaseRequest $request): bool
    {
        return $this->check($user, 'submit', $request);
    }

    public function approve(User $user, PurchaseRequest $request): bool
    {
        return $this->check($user, 'approve', $request);
    }

    public function reject(User $user, PurchaseRequest $request): bool
    {
        return $this->check($user, 'reject', $request);
    }

    /** Return a submitted request to its author for more detail. Not a rejection. */
    public function review(User $user, PurchaseRequest $request): bool
    {
        return $this->check($user, 'review', $request);
    }

    /** Cancel it, reopen it, correct it after submission. */
    public function manage(User $user, PurchaseRequest $request): bool
    {
        return $this->check($user, 'manage', $request);
    }
}
