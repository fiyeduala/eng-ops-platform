<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ncr;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The engineering answer has been given.
 *
 * The notification carries no disposition detail. "Use as is" on a structural element is a decision
 * people read a great deal into, and it should be read on the record, where the justification that
 * NcrService insists on is attached to it.
 */
class NcrDispositioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ncr $ncr,
        public readonly ?int $actorId = null,
    ) {}
}
