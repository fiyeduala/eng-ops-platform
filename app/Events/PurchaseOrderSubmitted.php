<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order has been put up for approval.
 *
 * The last point at which the commitment can be stopped without a conversation with a supplier, so
 * of everything in the chain this is the one that most deserves to interrupt somebody.
 */
class PurchaseOrderSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly ?int $actorId = null,
    ) {}
}
