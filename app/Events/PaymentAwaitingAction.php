<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A payment needs its next pair of hands: `approve` once submitted, `pay` once approved. */
class PaymentAwaitingAction
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?int $actorId,
        public readonly string $ability,
    ) {}
}
