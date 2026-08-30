<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Announcement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A notice went up on the board.
 *
 * The only event in the system whose natural audience is the entire tenant, which is why the
 * listener is the one place `Audience::organization()` is used and why the catalogue defaults
 * `announcement.published` to in-app only. An announcement that arrives by email for every one of
 * four hundred people is how a company learns to filter mail from its own systems.
 */
class AnnouncementPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Announcement $announcement,
        public readonly ?int $actorId = null,
    ) {}
}
