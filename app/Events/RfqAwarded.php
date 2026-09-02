<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Quotation;
use App\Models\Rfq;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An enquiry has been decided: one quotation won and the rest did not.
 *
 * Carries the winning quotation because the award is the only moment where the enquiry and one
 * particular response are the same fact. Everything else about the decision — the reason, the
 * marks, the price — stays on the records, where confidentiality still applies (§68).
 */
class RfqAwarded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Rfq $rfq,
        public readonly Quotation $quotation,
        public readonly ?int $actorId = null,
    ) {}
}
