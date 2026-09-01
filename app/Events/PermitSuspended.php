<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Permit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stop.
 *
 * Urgent and mandatory in the notification catalogue, and mandatory is the important half: a
 * suspension somebody has switched notifications off for is a suspension people keep working
 * through.
 */
class PermitSuspended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Permit $permit,
        public readonly ?int $actorId = null,
    ) {}
}
