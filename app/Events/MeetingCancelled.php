<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting is not happening.
 *
 * The only `urgent` key in the catalogue that fires from this module, because it is the one whose
 * value is entirely in arriving before people set off for it. Urgent means it ignores quiet hours,
 * and that is the correct trade at seven in the morning for a meeting at eight.
 */
class MeetingCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
