<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ncr;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A non-conformance has been issued to the other side.
 *
 * Issue rather than creation: an NCR is drafted and checked before it is sent, and the date it was
 * sent is the date the response clock starts.
 */
class NcrIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ncr $ncr,
        public readonly ?int $actorId = null,
    ) {}
}
