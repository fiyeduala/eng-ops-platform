<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\QualityInspection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "The rebar is ready — come and look before we pour."
 *
 * Work stops while an inspection is outstanding. That is what makes this notification worth sending
 * and what makes the gap between this event and the result the one number the register exists to
 * expose.
 */
class QualityInspectionRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly QualityInspection $inspection,
        public readonly ?int $actorId = null,
    ) {}
}
