<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PermitSuspended;
use App\Listeners\Concerns\ResolvesActor;

/**
 * Stop (key `permit.suspended`).
 *
 * THE REASON IS CARRIED, which is the deliberate exception to this application's habit of keeping
 * substance out of notification bodies. Everywhere else the body says a thing happened and the
 * record says what it was, because the substance is commercially or personally sensitive. A
 * suspension reason is neither: it is a safety instruction, it is worth nothing if it arrives an
 * hour later when somebody gets to a desk, and the dispatcher has already restricted delivery to
 * people who can open the permit.
 *
 * EVERY PARTY TO THE PERMIT IS TOLD — applicant, issuer and the supervisor who accepted it. A
 * suspension that reaches only the person who requested the work is a suspension the gang never
 * hears about.
 */
class NotifyPermitSuspended
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PermitSuspended $event): void
    {
        $permit = $event->permit;

        $parties = array_values(array_unique(array_filter([
            $permit->requested_by,
            $permit->issued_by,
            $permit->accepted_by,
        ], fn ($id) => $id !== null)));

        $audience = Audience::user($parties)->orFollowers($permit);

        $reason = filled($permit->suspension_reason)
            ? ' Reason: '.$permit->suspension_reason
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'permit.suspended',
                title: 'Permit suspended: '.$permit->number.' — '.$permit->title,
                body: 'Work under '.$permit->number.' has been suspended and must stop.'.$reason,
                actionUrl: route('permits.show', $permit, absolute: false),
            ),
            audience: $audience,
            subject: $permit,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $permit),
            projectId: $permit->project_id !== null ? (int) $permit->project_id : null,
        );
    }
}
