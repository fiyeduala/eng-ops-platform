<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WorkflowStageInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A stage opened and is now sitting in somebody's inbox.
 *
 * Fired on stage ENTRY, on reassignment, and when a rejection reopens an earlier stage — three
 * moments that look different from the engine but identical from the approver's side: something is
 * now waiting on me that was not before. Firing one event for all three is what keeps the inbox and
 * the notification honest about each other.
 *
 * Carries the stage instance rather than the subject, because the frozen assignee list is the whole
 * audience and it lives here. The listener re-reads it and still runs every candidate past the
 * SUBJECT's policy, because being on an assignee list is not the same fact as being allowed to know
 * what the record says (§68).
 */
class ApprovalRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkflowStageInstance $stageInstance,
        public readonly ?int $actorId = null,
    ) {}
}
