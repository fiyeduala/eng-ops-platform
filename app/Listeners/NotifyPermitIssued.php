<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PermitIssued;
use App\Listeners\Concerns\ResolvesActor;

/**
 * Dangerous work has been authorised (key `permit.issued`).
 *
 * THE EXPIRY IS IN THE BODY, because it is the only fact about a permit that hurts somebody when it
 * is not known. A permit is a licence with a clock on it, and "valid until 18:00" belongs in the
 * message rather than one click away.
 *
 * The audience is the applicant and anybody following. Telling the rest of the area — which on a
 * live plant is a genuine requirement — is a `notify` auto-action configured against the issued
 * status, because who else needs to know depends entirely on how a company organises its areas
 * (§32).
 */
class NotifyPermitIssued
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PermitIssued $event): void
    {
        $permit = $event->permit;

        $audience = Audience::followers($permit);

        if ($permit->requested_by !== null) {
            $audience = $audience->orUser((int) $permit->requested_by);
        }

        $validity = $permit->valid_to !== null
            ? ' Valid until '.$permit->valid_to->format('j M Y H:i').'.'
            : '';

        $where = filled($permit->location) ? ' at '.$permit->location : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'permit.issued',
                title: 'Permit issued: '.$permit->number.' — '.$permit->title,
                body: $permit->number.' has been issued'.$where.'.'.$validity
                    .' It must be accepted on site before work starts.',
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
