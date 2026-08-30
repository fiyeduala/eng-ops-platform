<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\TaskAssigned;
use App\Models\Task;
use App\Models\User;

/**
 * Tell somebody that work has landed on them (docs/10 §5, key `task.assigned`).
 *
 * The first listener in the system, and therefore the one that sets the shape of all the others.
 * Three things about that shape are deliberate:
 *
 * NOT ShouldQueue. Tempting — a fan-out inside a request is exactly what queues are for — but the
 * event carries a Task through SerializesModels, and unserializing a Task on a worker means an
 * Eloquent find with no tenant bound, which the OrganizationScope compiles to `organization_id = 0`
 * and fails closed on. That is the global scope working correctly; it just makes a queued listener
 * throw ModelNotFoundException rather than deliver. The expensive half — the email — is queued by
 * the dispatcher, and the cheap half stays in the request where the tenant is known.
 *
 * It states an audience, not a recipient. `Audience::user()` is a candidate list; whether the
 * assignee is actually told is NotificationDispatcher's decision after it puts them through
 * TaskPolicy. That may look redundant here — surely the person just assigned the task can see it —
 * but it is not: assignment writes `assignee_id`, and whether that confers sight depends on whether
 * this account holds `task.view` at `assigned` scope. An account that does not (a contractor whose
 * role was narrowed, a leaver mid-offboarding) gets nothing, silently.
 *
 * It re-resolves the actor from the id rather than trusting a serialized model, per the note on the
 * event itself.
 */
class NotifyTaskAssignee
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(TaskAssigned $event): void
    {
        if ($event->isSelfAssignment()) {
            return;
        }

        $task = $event->task;

        $reference = $task->number ?: 'Task #'.$task->getKey();

        $message = NotificationMessage::make(
            key: 'task.assigned',
            title: $event->isReassignment()
                ? $reference.' has been reassigned to you'
                : $reference.' has been assigned to you',
            body: $this->body($task),

            // A path, not a URL. The dispatcher turns it absolute for email and the in-app list
            // sends it through notifications.open, which re-authorizes before redirecting.
            actionUrl: route('tasks.show', $task, absolute: false),
        );

        $this->dispatcher->dispatch(
            notification: $message,
            audience: Audience::user($event->assigneeId),
            subject: $task,
            requiredAbility: 'view',
            causer: $this->actor($event),
        );
    }

    /**
     * The one-line summary that appears under the title.
     *
     * Kept to the task's own title and due date — facts the recipient is about to be shown anyway on
     * a page they have just been authorized for. Nothing about who else is involved, and nothing
     * from the description: a description is free text somebody else wrote, and free text is how
     * confidential detail ends up in a subject line.
     */
    private function body(Task $task): string
    {
        $line = (string) $task->title;

        if ($task->due_on !== null) {
            $line .= ' — due '.$task->due_on->format('j M Y');
        }

        return $line;
    }

    private function actor(TaskAssigned $event): ?User
    {
        if ($event->actorId === null) {
            return null;
        }

        // Pinned to the task's tenant explicitly rather than relying on whatever context happens to
        // be bound. A user id is unique per installation, not per company (§75).
        return $this->context->withoutScope(fn () => User::query()
            ->where('id', $event->actorId)
            ->where('organization_id', $event->task->organization_id)
            ->first());
    }
}
