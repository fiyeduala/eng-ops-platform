<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\DocumentRevisionRequested;
use App\Listeners\Concerns\ResolvesActor;

/**
 * The route came back asking for changes (key `document.revision_requested`).
 *
 * Mandatory in the catalogue — the one class of message a user cannot turn off — because it is a
 * request for work addressed to a specific person. A document sitting in "revision requested" that
 * its author never heard about is the failure mode this whole module exists to prevent.
 *
 * Narrower than the approval notice: the owner and the author only. Followers are not told, because
 * "somebody asked for changes" is an intermediate state of work in progress rather than a fact about
 * the document, and broadcasting it turns an ordinary review comment into a public one.
 *
 * The reason IS included. Like a rejection comment, it was written for this reader, and without it
 * the notification says only that something is wrong somewhere.
 */
class NotifyDocumentRevisionRequested
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(DocumentRevisionRequested $event): void
    {
        $document = $event->document;

        $recipients = array_values(array_unique(array_filter([
            $document->owner_id !== null ? (int) $document->owner_id : null,
            $document->created_by !== null ? (int) $document->created_by : null,
        ])));

        if ($recipients === []) {
            return;
        }

        $body = 'Changes were requested on '.$document->displayName().'.';

        if (filled($event->reason)) {
            $body .= ' '.mb_substr((string) $event->reason, 0, 400);
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'document.revision_requested',
                title: 'Revision requested: '.$document->displayName(),
                body: $body,
                actionUrl: route('documents.show', $document, absolute: false),
            ),
            audience: Audience::user($recipients),
            subject: $document,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $document),
            projectId: $document->project_id !== null ? (int) $document->project_id : null,
        );
    }
}
