<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A stage resolved to nobody and the route stopped.
 *
 * This is the event that pays for the engine's decision to halt rather than skip. Halting is only
 * defensible if somebody is told — a silent stall is just a slower version of the record vanishing.
 *
 * It goes to people who hold `approval.reassign`, which is deliberately a permission rather than a
 * role name: the company decides who fixes routes (§5).
 */
class WorkflowStalled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly WorkflowStageInstance $stageInstance,
    ) {}
}
