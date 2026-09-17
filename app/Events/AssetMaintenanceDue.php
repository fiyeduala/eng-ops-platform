<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AssetMaintenance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A planned service or statutory inspection is coming up.
 *
 * DISPATCHED BY A SCHEDULED SWEEP, not by anybody pressing anything — which makes it the one event
 * in this part with no actor. That is why the listener has no causer to resolve: the system noticed,
 * and attributing the notice to whoever happened to run the scheduler would be a lie in the audit
 * trail.
 *
 * `$daysRemaining` TRAVELS WITH IT because the sweep already computed it and the wording depends on
 * it — "due in 7 days" and "overdue by 3" are the same event with opposite urgency, and a listener
 * that recomputed the figure from `due_on` would be answering as of a different clock than the one
 * that decided to send anything.
 */
class AssetMaintenanceDue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AssetMaintenance $maintenance,
        public readonly int $daysRemaining = 0,
    ) {}
}
