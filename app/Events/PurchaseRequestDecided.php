<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody answered a requisition — approved, returned or rejected.
 *
 * All three, not only approval. "Returned to the requester" is the answer that most often dies in
 * silence, and a register that announced only approvals would be the thing killing it.
 *
 * The decision is read off the record rather than carried on the event, so a listener cannot be
 * told one thing while the register says another.
 */
class PurchaseRequestDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseRequest $purchaseRequest,
        public readonly ?int $actorId = null,
    ) {}
}
