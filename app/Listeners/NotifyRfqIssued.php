<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\RfqIssued;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An enquiry went out (key `rfq.issued`).
 *
 * THE CLOSING DATE IS THE WHOLE MESSAGE. Everything else about an enquiry can be read at leisure;
 * the date responses stop being accepted is what anybody who has to chase a supplier needs today.
 *
 * The requester is told because their requisition has visibly moved. Nobody else gets an email for
 * this one — it is news, not work, and the catalogue defaults it to the bell alone.
 */
class NotifyRfqIssued
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(RfqIssued $event): void
    {
        $rfq = $event->rfq;

        $audience = Audience::followers($rfq);

        if ($rfq->created_by !== null) {
            $audience = $audience->orUser((int) $rfq->created_by);
        }

        // The person whose requisition caused it. Resolved through the relation so the tenant scope
        // applies, and skipped silently when the enquiry was raised from nothing.
        if ($rfq->purchase_request_id !== null) {
            $rfq->loadMissing('purchaseRequest');

            if ($rfq->purchaseRequest?->created_by !== null) {
                $audience = $audience->orUser((int) $rfq->purchaseRequest->created_by);
            }
        }

        $closes = $rfq->closes_at !== null
            ? ' Responses close '.$rfq->closes_at->format('j M Y').'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'rfq.issued',
                title: 'Enquiry issued: '.$rfq->number.' — '.$rfq->title,
                body: $rfq->number.' has gone out to the invited suppliers.'.$closes,
                actionUrl: route('rfqs.show', $rfq, absolute: false),
            ),
            audience: $audience,
            subject: $rfq,
            causer: $this->resolveActor($event->actorId, $rfq),
            projectId: $rfq->project_id !== null ? (int) $rfq->project_id : null,
        );
    }
}
