<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The minutes were released.
 *
 * Fires on circulation, not on recording. Those are two acts with two permissions (see
 * MeetingPolicy), and notifying on the first would tell everybody who was in the room that a draft
 * exists — which is precisely the state Meeting::minutesVisibleTo() withholds from them.
 */
class MeetingMinutesCirculated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly ?int $actorId = null,
    ) {}
}
