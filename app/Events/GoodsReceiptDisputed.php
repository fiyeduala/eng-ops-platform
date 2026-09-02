<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\GoodsReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The delivery is wrong, and somebody has to take the supplier up on it.
 *
 * The one procurement notification that is about a thing going badly, and the reason it is urgent
 * is commercial rather than operational: a dispute raised a fortnight after the invoice was passed
 * for payment is a dispute already lost.
 *
 * CARRIES THE REASON, unusually for an event here. The reason is not stored on the receipt —
 * it lives in the activity entry, where it is protected from ordinary editing (§59) — so a
 * listener that wanted to repeat it back would otherwise have to read the audit trail for it.
 */
class GoodsReceiptDisputed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly GoodsReceipt $goodsReceipt,
        public readonly string $reason = '',
        public readonly ?int $actorId = null,
    ) {}
}
