<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * People were added to a meeting.
 *
 * Carries the ids of the NEWLY invited only, not the whole participant list. Re-sending an
 * invitation to everybody each time one person is added is the behaviour that teaches people to
 * ignore meeting mail, and the distinction cannot be recovered downstream — by the time the
 * listener runs, the new row and the three-week-old row look identical.
 *
 * @property-read array<int,int> $userIds
 */
class MeetingInvited
{
    use Dispatchable, SerializesModels;

    /** @param  array<int,int>  $userIds */
    public function __construct(
        public readonly Meeting $meeting,
        public readonly array $userIds,
        public readonly ?int $actorId = null,
    ) {}
}
