<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PurchaseOrderSubmitted;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An order is waiting for approval (key `purchase_order.submitted`).
 *
 * FILTERED ON `approve`, like the requisition it descends from, and for the same reason: this is the
 * last point at which the commitment can be stopped without a conversation with a supplier, so it
 * must reach the people who can stop it and nobody else.
 *
 * THE SUPPLIER'S NAME IS IN THE BODY AND THE VALUE IS NOT. An approver deciding whether to open this
 * needs to know who it is with; the amount is behind the record's own confidentiality, and this
 * notification does not carry it past that.
 */
class NotifyPurchaseOrderSubmitted
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PurchaseOrderSubmitted $event): void
    {
        $order = $event->purchaseOrder;
        $order->loadMissing('vendor');

        $audience = Audience::followers($order);

        if ($order->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $order->org_unit_id);
        }

        if ($order->created_by !== null) {
            $audience = $audience->orSupervisorOf((int) $order->created_by);
        }

        $with = $order->vendor ? ' with '.$order->vendor->name : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'purchase_order.submitted',
                title: 'Order awaiting approval: '.$order->number,
                body: $order->number.$with.' has been submitted for approval. Nothing reaches the supplier until it is approved and issued.',
                actionUrl: route('purchase-orders.show', $order, absolute: false),
            ),
            audience: $audience,
            subject: $order,
            requiredAbility: 'approve',
            causer: $this->resolveActor($event->actorId, $order),
            projectId: $order->project_id !== null ? (int) $order->project_id : null,
        );
    }
}
