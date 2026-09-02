<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PurchaseOrderIssued;
use App\Listeners\Concerns\ResolvesActor;

/**
 * The order reached the supplier (key `purchase_order.issued`).
 *
 * AIMED DOWNSTREAM, NOT AT THE APPROVERS. The people who needed to know about this order while it
 * was a decision have already been told; the people who need to know now are the ones waiting for
 * the material — the requester whose requisition started it, and anybody following the order.
 *
 * THE EXPECTED DATE IS THE POINT OF IT. "Your steel is ordered" is pleasant; "your steel is ordered
 * and due on the 14th" is something a programme can be built on.
 */
class NotifyPurchaseOrderIssued
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PurchaseOrderIssued $event): void
    {
        $order = $event->purchaseOrder;
        $order->loadMissing('vendor:id,name');

        $audience = Audience::followers($order);

        if ($order->created_by !== null) {
            $audience = $audience->orUser((int) $order->created_by);
        }

        if ($order->purchase_request_id !== null) {
            $order->loadMissing('purchaseRequest:id,created_by');

            if ($order->purchaseRequest?->created_by !== null) {
                $audience = $audience->orUser((int) $order->purchaseRequest->created_by);
            }
        }

        $with = $order->vendor ? ' to '.$order->vendor->name : '';

        $due = $order->required_by !== null
            ? ' Due '.$order->required_by->format('j M Y').'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'purchase_order.issued',
                title: 'Order issued: '.$order->number,
                body: $order->number.' has been issued'.$with.'.'.$due,
                actionUrl: route('purchase-orders.show', $order, absolute: false),
            ),
            audience: $audience,
            subject: $order,
            causer: $this->resolveActor($event->actorId, $order),
            projectId: $order->project_id !== null ? (int) $order->project_id : null,
        );
    }
}
