<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\DocumentApproved;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A revision became the controlled copy (key `document.approved`).
 *
 * WHO IS TOLD, and why it is a short list. The owner and the author, because the thing they were
 * waiting on has happened. Everybody FOLLOWING the document, because following is an explicit
 * request to hear about exactly this. Nobody else.
 *
 * It is tempting to announce an approved procedure to the whole department — that is, after all,
 * what a controlled document is for. But the audience for "this now applies to you" is a
 * distribution decision the organization makes per register, not a hard-coded consequence of
 * approval, and getting it wrong means every engineer in the company receives a notification for
 * every drawing anybody approves. The register's own distribution list, and the transmittal, are
 * where that belongs.
 *
 * The dispatcher still filters everyone on this list against `view` on the document itself, so a
 * follower who has since lost access hears nothing (§68).
 */
class NotifyDocumentApproved
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(DocumentApproved $event): void
    {
        $document = $event->document;

        $recipients = array_values(array_unique(array_filter([
            $document->owner_id !== null ? (int) $document->owner_id : null,
            $document->created_by !== null ? (int) $document->created_by : null,
            $event->version->created_by !== null ? (int) $event->version->created_by : null,
        ])));

        $audience = Audience::user($recipients)->orFollowers($document);

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'document.approved',
                title: 'Approved: '.$document->displayName(),
                body: 'Revision '.$event->version->revision.' of '.$document->displayName()
                    .' has been approved and is now the current issue.',
                actionUrl: route('documents.show', $document, absolute: false),
            ),
            audience: $audience,
            subject: $document,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $document),
            projectId: $document->project_id !== null ? (int) $document->project_id : null,
        );
    }
}
