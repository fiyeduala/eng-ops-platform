<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting moved.
 *
 * Carries where it moved FROM, as plain strings rather than a Carbon or a Meeting snapshot. The
 * previous time is the only thing a recipient actually needs to reconcile against the entry already
 * in their own diary, and by the time any listener runs the model holds only the new value.
 */
class MeetingRescheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly ?string $previousStartsAt = null,
        public readonly ?string $previousLocation = null,
        public readonly ?int $actorId = null,
    ) {}

    public function timeChanged(): bool
    {
        return $this->previousStartsAt !== null
            && $this->previousStartsAt !== $this->meeting->starts_at?->toDateTimeString();
    }

    public function placeChanged(): bool
    {
        return $this->previousLocation !== $this->meeting->location;
    }
}
