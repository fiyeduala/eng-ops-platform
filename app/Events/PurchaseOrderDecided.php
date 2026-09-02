<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody answered an order — approved, returned for amendment or rejected.
 *
 * Approval is not issue. This fires when the internal authority is settled; the supplier learns
 * nothing until PurchaseOrderIssued, and the two are days apart often enough that collapsing them
 * would make the register lie about when the company committed.
 */
class PurchaseOrderDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly ?int $actorId = null,
    ) {}
}
