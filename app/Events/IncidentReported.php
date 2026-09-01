<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something happened on site.
 *
 * THE MOST URGENT EVENT IN THE APPLICATION, and the one its listener has to be most careful with.
 * An incident is frequently confidential — an injury, a name, a medical detail — so the
 * notification says that an incident has been reported and where, and never what was injured or to
 * whom. The dispatcher will not deliver to anybody who cannot open the record, and the body must
 * still be safe for everybody who can (§68).
 *
 * Fired once, at creation. Escalation is a separate event, because escalating is a decision rather
 * than a repeat of the report.
 *
 * THIS EVENT IS NOT WHAT SPAWNS THE INVESTIGATION. Phase 4's exit criterion is that an incident
 * spawns an investigation and a corrective action THROUGH CONFIGURATION ALONE, so that work is done
 * by workflow auto-actions on the status the incident lands in — not by a listener nailed to this
 * event, which would be one company's process compiled into the application (§32, §73).
 */
class IncidentReported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Incident $incident,
        public readonly ?int $actorId = null,
    ) {}
}
