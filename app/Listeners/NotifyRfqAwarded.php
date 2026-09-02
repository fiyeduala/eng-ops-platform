<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\RfqAwarded;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An enquiry was awarded (key `rfq.awarded`).
 *
 * THE WINNER'S NAME IS IN THE BODY; THE PRICE IS NOT. Who was chosen is the fact that unblocks the
 * next person's work, and it is already on the enquiry that everybody here can open. The amount is
 * on the quotation, which is confidential by default and stays that way (§68).
 *
 * THE SUBJECT IS THE ENQUIRY, NOT THE QUOTATION, so the dispatcher's `view` filter is asked about
 * the record these people can actually reach. Filtering on the quotation would silence almost
 * everybody, including the requester the award most concerns.
 */
class NotifyRfqAwarded
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(RfqAwarded $event): void
    {
        $rfq = $event->rfq;
        $quotation = $event->quotation;

        $quotation->loadMissing('vendor:id,name');

        $audience = Audience::followers($rfq);

        if ($rfq->created_by !== null) {
            $audience = $audience->orUser((int) $rfq->created_by);
        }

        if ($rfq->purchase_request_id !== null) {
            $rfq->loadMissing('purchaseRequest:id,created_by');

            if ($rfq->purchaseRequest?->created_by !== null) {
                $audience = $audience->orUser((int) $rfq->purchaseRequest->created_by);
            }
        }

        $winner = $quotation->vendor?->name ?? 'a supplier';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'rfq.awarded',
                title: 'Enquiry awarded: '.$rfq->number.' — '.$winner,
                body: $rfq->number.' has been awarded to '.$winner.'. An order can now be raised against it.',
                actionUrl: route('rfqs.show', $rfq, absolute: false),
            ),
            audience: $audience,
            subject: $rfq,
            causer: $this->resolveActor($event->actorId, $rfq),
            projectId: $rfq->project_id !== null ? (int) $rfq->project_id : null,
        );
    }
}
