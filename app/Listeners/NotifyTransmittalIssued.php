<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\TransmittalIssued;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A transmittal went out (key `transmittal.issued`).
 *
 * THE RECIPIENT IS NOT NOTIFIED FROM HERE, and that is the important sentence. The person a
 * transmittal is addressed to is usually outside this system entirely — a client's document
 * controller, a contractor's engineer — and the platform has no account for them, no permission
 * record, and therefore no way to decide what they may see. Emailing them a link would be handing
 * an unauthenticated URL to a party the access engine knows nothing about, which is exactly the
 * shape of the mistake §67 and §68 are written to prevent. Outbound issue is a deliberate export:
 * the cover sheet and the files are downloaded by the issuer and sent through whatever channel the
 * contract specifies.
 *
 * So the audience is internal: the issuer's own side of the transaction. The project team, because
 * "Rev C went to the client on Tuesday" is a fact the team needs; anybody following the transmittal;
 * and the owners of the documents on it, who need to know their drawing has left the building.
 */
class NotifyTransmittalIssued
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(TransmittalIssued $event): void
    {
        $transmittal = $event->transmittal;
        $transmittal->loadMissing('items.documentVersion.document');

        $documentOwners = $transmittal->items
            ->map(fn ($item) => $item->documentVersion?->document?->owner_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $audience = Audience::user($documentOwners)->orFollowers($transmittal);

        if ($transmittal->project_id !== null) {
            $audience = $audience->orProject((int) $transmittal->project_id);
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'transmittal.issued',
                title: 'Issued: '.$transmittal->number.' to '.$transmittal->to_name,
                body: $transmittal->number.' was issued to '.$transmittal->to_name
                    .' with '.$transmittal->items->count().' document(s).',
                actionUrl: route('transmittals.show', $transmittal, absolute: false),
            ),
            audience: $audience,
            subject: $transmittal,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $transmittal),
            projectId: $transmittal->project_id !== null ? (int) $transmittal->project_id : null,
        );
    }
}
