<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody was put on a task.
 *
 * The first event in the system, and it exists now rather than later because the notification
 * engine — the very next module — needs something to listen to, and because §90 asks modules to
 * announce what happened rather than to call each other. A future HSE module that wants to know
 * when its corrective action gets an owner subscribes here; it does not get a line added to
 * TaskService.
 *
 * IDs rather than User models on purpose. An event is queued and replayed later, and a serialized
 * User is a snapshot of an account that may since have been disabled, moved, or had its roles
 * changed. Every listener must re-resolve the person and re-check what they may see at the moment
 * it acts (§68) — a notification about a task the recipient can no longer open is a leak whether
 * or not they could open it when the event fired.
 */
class TaskAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly int $assigneeId,
        public readonly ?int $previousAssigneeId = null,
        public readonly ?int $actorId = null,
    ) {}

    /** True when the task was taken off somebody else, which reads differently in a notification. */
    public function isReassignment(): bool
    {
        return $this->previousAssigneeId !== null
            && $this->previousAssigneeId !== $this->assigneeId;
    }

    /** Assigning work to yourself needs no notification. */
    public function isSelfAssignment(): bool
    {
        return $this->actorId !== null && $this->actorId === $this->assigneeId;
    }
}
