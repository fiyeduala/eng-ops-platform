<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Rfq;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An enquiry has gone out to the invited suppliers.
 *
 * THE SUPPLIERS ARE NOT NOTIFIED BY THIS. They are not users of this installation, and the
 * dispatcher only ever delivers to people inside the organization. What goes to them is the enquiry
 * document, sent by whatever means the buyer uses; this event tells the people here that the clock
 * on responses has started.
 */
class RfqIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Rfq $rfq,
        public readonly ?int $actorId = null,
    ) {}
}
