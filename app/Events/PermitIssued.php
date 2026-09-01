<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Permit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dangerous work has been authorised.
 *
 * Worth telling people about beyond the applicant: a hot-work permit issued on a live plant changes
 * what everybody else in that area can safely do for the rest of the shift.
 */
class PermitIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Permit $permit,
        public readonly ?int $actorId = null,
    ) {}
}
