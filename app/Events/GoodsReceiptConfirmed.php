<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\GoodsReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Goods turned up and somebody counted them.
 *
 * Confirmation rather than entry: a half-typed receipt is not evidence of anything, and it is the
 * confirmation that names a receiver and a time and lets the order count the quantities.
 */
class GoodsReceiptConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly GoodsReceipt $goodsReceipt,
        public readonly ?int $actorId = null,
    ) {}
}
