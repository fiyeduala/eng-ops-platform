<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VehicleDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Insurance, a licence or a permit is about to lapse.
 *
 * THE URGENT ONE IN THIS PART, and the urgency is legal rather than operational: a vehicle whose
 * insurance expired last week is a vehicle that must not leave the yard, and if it does, the
 * company's exposure is not a maintenance backlog but an uninsured collision.
 *
 * A WARNING IS NOT A STATUS. Nothing in this event marks the document expired — expiry is decided
 * by comparing `expires_on` to today, every time anybody asks, so a scheduler that stops running
 * makes the company miss a WARNING but can never make the system call a lapsed certificate valid.
 * That asymmetry is the design and it is worth keeping: the query is the truth, the notification is
 * the courtesy.
 */
class VehicleDocumentExpiring
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly VehicleDocument $document,
        public readonly int $daysRemaining = 0,
    ) {}
}
