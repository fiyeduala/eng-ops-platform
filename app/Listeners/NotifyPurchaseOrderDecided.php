<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PurchaseOrderDecided;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An order was answered (key `purchase_order.decided`).
 *
 * APPROVED IS NOT ISSUED, and the body says so, because the commonest mistake at this point is a
 * buyer who reads "approved" and assumes the supplier has it. Issuing is a separate act with its
 * own notification.
 */
class NotifyPurchaseOrderDecided
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PurchaseOrderDecided $event): void
    {
        $order = $event->purchaseOrder;
        $key = $order->status?->key;

        [$verb, $tail] = match ($key) {
            'approved' => ['approved', ' It still has to be issued before the supplier has it.'],
            'rejected' => ['rejected', ''],
            'draft' => ['returned for amendment', ''],
            default => ['answered', ''],
        };

        $reason = ($key !== 'approved' && filled($order->decision_reason))
            ? ' Reason: '.$order->decision_reason
            : '';

        $audience = Audience::followers($order);

        foreach ([$order->created_by, $order->submitted_by] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'purchase_order.decided',
                title: 'Order '.$verb.': '.$order->number,
                body: $order->number.' has been '.$verb.'.'.$reason.$tail,
                actionUrl: route('purchase-orders.show', $order, absolute: false),
            ),
            audience: $audience,
            subject: $order,
            causer: $this->resolveActor($event->actorId, $order),
            projectId: $order->project_id !== null ? (int) $order->project_id : null,
        );
    }
}
