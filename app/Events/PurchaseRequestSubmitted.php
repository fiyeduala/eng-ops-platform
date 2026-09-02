<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A requisition has been put up for a decision.
 *
 * Submission rather than creation, for the reason the whole register is built on: a draft is a
 * person thinking, and thinking is nobody else's business. The moment it is submitted it becomes
 * somebody's outstanding work, and that is what is worth interrupting them for.
 *
 * Fires again on resubmission after a return, deliberately — an approver who sent it back to have a
 * size confirmed has no other way of learning that the answer came.
 */
class PurchaseRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseRequest $purchaseRequest,
        public readonly ?int $actorId = null,
    ) {}
}
