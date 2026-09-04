<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\GoodsReceiptConfirmed;
use App\Listeners\Concerns\ResolvesActor;

/**
 * Goods arrived (key `goods_receipt.received`).
 *
 * THE AUDIENCE IS THE ORDER'S PEOPLE, THE SUBJECT IS THE RECEIPT. The people who care about a
 * delivery are the ones who raised and approved the order it came against, and they are found
 * through that order; but the record being disclosed is the receipt, so that is what the
 * dispatcher's `view` filter is asked about (§68). Somebody who can see the order and not this
 * particular receipt is told nothing, which is the correct answer.
 *
 * THE SHORTFALL IS THE INTERESTING PART. "Delivery received" is filing; "delivery received, four of
 * the six lines outstanding" is why a buyer opens it.
 */
class NotifyGoodsReceiptConfirmed
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(GoodsReceiptConfirmed $event): void
    {
        $receipt = $event->goodsReceipt;
        $receipt->loadMissing('purchaseOrder.vendor', 'purchaseOrder.status');

        $order = $receipt->purchaseOrder;

        $audience = Audience::followers($receipt);

        if ($order !== null) {
            $audience = $audience->orFollowers($order);

            foreach ([$order->created_by, $order->approved_by] as $userId) {
                if ($userId !== null) {
                    $audience = $audience->orUser((int) $userId);
                }
            }
        }

        $status = $order?->status?->key === 'received'
            ? ' The order is now received in full.'
            : ' The order is still expecting goods.';

        $from = $order?->vendor ? ' from '.$order->vendor->name : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'goods_receipt.received',
                title: 'Delivery received: '.$receipt->number.($order ? ' against '.$order->number : ''),
                body: 'A delivery'.$from.' has been booked in as '.$receipt->number.'.'.($order ? $status : ''),
                actionUrl: route('goods-receipts.show', $receipt, absolute: false),
            ),
            audience: $audience,
            subject: $receipt,
            causer: $this->resolveActor($event->actorId, $receipt),
            projectId: $order?->project_id !== null ? (int) $order->project_id : null,
        );
    }
}
