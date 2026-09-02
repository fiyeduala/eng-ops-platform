<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The order has reached the supplier. The money is committed.
 *
 * Announced to the people downstream of it rather than to the approvers: the requester wants to
 * know their material is coming, and the store wants to know something is due to arrive.
 */
class PurchaseOrderIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly ?int $actorId = null,
    ) {}
}
