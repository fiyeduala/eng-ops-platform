<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\InvestigationAssigned;
use App\Listeners\Concerns\ResolvesActor;

/**
 * Somebody has been asked to find out what happened (key `investigation.assigned`).
 *
 * Usually fired by a workflow auto-action rather than a person — that is the shape of Phase 4's
 * exit criterion — so the lead frequently learns of the assignment from this message and nothing
 * else. It therefore carries the due date, which is the only part of the assignment that has a
 * deadline attached to it.
 *
 * INVESTIGATIONS INHERIT THE INCIDENT'S CONFIDENTIALITY (see InvestigationService::openFor), so an
 * investigation into a restricted incident is itself restricted, and the dispatcher's authorization
 * filter will not deliver this to anybody who could not open it. That is the correct behaviour even
 * when it means the assignment notification does not arrive: an investigation somebody cannot read
 * is one they should not have been assigned, and the fix is the assignment, not the notification.
 */
class NotifyInvestigationAssigned
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(InvestigationAssigned $event): void
    {
        $investigation = $event->investigation;

        if ($investigation->lead_id === null) {
            return;
        }

        $due = $investigation->due_on !== null
            ? ' Findings are due by '.$investigation->due_on->format('j M Y').'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'investigation.assigned',
                title: 'Investigation assigned: '.$investigation->number,
                body: 'You are leading '.$investigation->number.' — '.$investigation->title.'.'.$due,
                actionUrl: route('investigations.show', $investigation, absolute: false),
            ),
            audience: Audience::user((int) $investigation->lead_id)->orFollowers($investigation),
            subject: $investigation,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $investigation),
            projectId: $investigation->project_id !== null ? (int) $investigation->project_id : null,
        );
    }
}
