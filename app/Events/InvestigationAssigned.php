<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Investigation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody has been asked to find out what happened.
 *
 * Usually raised by a workflow auto-action rather than by a person, so the actor is generally
 * whoever moved the incident's status rather than anybody who consciously chose the lead. The
 * notification is addressed to the lead and says what to investigate and by when.
 */
class InvestigationAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Investigation $investigation,
        public readonly ?int $actorId = null,
    ) {}
}
