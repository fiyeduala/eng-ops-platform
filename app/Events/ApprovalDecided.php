<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Approval;
use App\Models\WorkflowStageInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One person recorded one decision.
 *
 * Per DECISION, not per stage: on a three-person quorum this fires three times, and that is the
 * point. The record's owner wants to know that Ade has approved even though two more are still
 * outstanding, because that is the information that tells them whether to go chasing.
 *
 * The stage completing is a different fact, reported by the engine's own timeline entry and, for
 * documents, by `document.approved`. Conflating them would either spam the owner or leave them
 * blind until the end.
 */
class ApprovalDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkflowStageInstance $stageInstance,
        public readonly Approval $approval,
        public readonly ?int $actorId = null,
    ) {}
}
