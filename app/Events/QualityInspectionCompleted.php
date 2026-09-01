<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\QualityInspection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The verdict is in.
 *
 * Fired for a pass as well as a rejection. The requester is waiting on the answer either way, and a
 * register that only speaks up about failures teaches people that silence means nothing happened.
 */
class QualityInspectionCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly QualityInspection $inspection,
        public readonly ?int $actorId = null,
    ) {}
}
