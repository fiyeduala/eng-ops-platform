<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transmittal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Documents were formally issued, and the clock on the acknowledgement started.
 *
 * Fired on the draft -> issued transition only. A transmittal is a legal record of what was sent to
 * whom and when; re-firing it on an edit would put a second "we sent you this" in somebody's inbox
 * for a package that was sent once.
 */
class TransmittalIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Transmittal $transmittal,
        public readonly ?int $actorId = null,
    ) {}
}
