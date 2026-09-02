<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PurchaseRequestDecided;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A requisition was answered (key `purchase_request.decided`).
 *
 * THE DECISION IS READ OFF THE RECORD. The event carries no verb, so this listener cannot announce
 * an approval on a requisition the register shows as rejected — the status is the single source, as
 * everywhere else.
 *
 * THE REASON IS INCLUDED WHEN IT IS A REFUSAL, and only then. "Rejected" without a reason is the
 * message that generates the phone call this notification existed to save; an approval's optional
 * comment is a nicety that can be read on the record.
 */
class NotifyPurchaseRequestDecided
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PurchaseRequestDecided $event): void
    {
        $request = $event->purchaseRequest;
        $key = $request->status?->key;

        $verb = match ($key) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'draft' => 'returned to you for amendment',
            default => 'answered',
        };

        $reason = ($key === 'rejected' && filled($request->decision_reason))
            ? ' Reason: '.$request->decision_reason
            : '';

        $audience = Audience::followers($request);

        foreach ([$request->created_by, $request->submitted_by] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'purchase_request.decided',
                title: 'Requisition '.$verb.': '.$request->number,
                body: $request->number.' — '.$request->title.' — has been '.$verb.'.'.$reason,
                actionUrl: route('purchase-requests.show', $request, absolute: false),
            ),
            audience: $audience,
            subject: $request,
            causer: $this->resolveActor($event->actorId, $request),
            projectId: $request->project_id !== null ? (int) $request->project_id : null,
        );
    }
}
