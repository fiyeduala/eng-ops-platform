<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\CorrectiveActionAssigned;
use App\Listeners\Concerns\ResolvesActor;

/**
 * Something has to be done, by you, by a date (key `corrective_action.assigned`).
 *
 * THE MESSAGE DESCRIBES THE ACTION AND NEVER ITS SOURCE. A supervisor asked to fit a guard rail has
 * no business reading the injury detail of the incident that prompted it, which is why
 * CorrectiveActionService::openFrom() gives the action its own `internal` confidentiality instead of
 * inheriting the incident's, and why "arising from INC-2026-0031" appears nowhere in this body. The
 * action is a complete instruction on its own terms; the link to the source is on the record, behind
 * the source's own policy (§68).
 *
 * THE DUE DATE IS THE POINT OF THE NOTIFICATION. An action with no date is a wish, and this is the
 * one place the assignee is told the date without having to go and look.
 */
class NotifyCorrectiveActionAssigned
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(CorrectiveActionAssigned $event): void
    {
        $action = $event->action;

        if ($action->assigned_to === null) {
            return;
        }

        $due = $action->due_on !== null
            ? ' Due by '.$action->due_on->format('j M Y').'.'
            : '';

        $priority = filled($action->priority)
            ? ' Priority: '.str_replace('_', ' ', (string) $action->priority).'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'corrective_action.assigned',
                title: 'Action assigned: '.$action->number.' — '.$action->title,
                body: $action->number.' has been assigned to you.'.$due.$priority,
                actionUrl: route('corrective-actions.show', $action, absolute: false),
            ),
            audience: Audience::user((int) $action->assigned_to),
            subject: $action,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $action),
            projectId: $action->project_id !== null ? (int) $action->project_id : null,
        );
    }
}
