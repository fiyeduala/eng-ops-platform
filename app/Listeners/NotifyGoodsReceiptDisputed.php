<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\GoodsReceiptDisputed;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A delivery is disputed (key `goods_receipt.disputed`).
 *
 * THE ONLY PROCUREMENT NOTIFICATION THAT IS URGENT BY NATURE, and the urgency is commercial: a
 * dispute raised after the invoice has been passed for payment is a dispute already lost. The buyer
 * has to know today, not when they next open the register.
 *
 * THE REASON TRAVELS WITH IT, unlike the money elsewhere in this module. It is the sentence the
 * supplier will be taken up on, it is not confidential from anybody who can already see the
 * delivery, and a dispute notification without it only produces a phone call asking what was wrong.
 */
class NotifyGoodsReceiptDisputed
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(GoodsReceiptDisputed $event): void
    {
        $receipt = $event->goodsReceipt;
        $receipt->loadMissing('purchaseOrder.vendor:id,name');

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

        $with = $order?->vendor ? ' with '.$order->vendor->name : '';

        $reason = filled($event->reason) ? ' '.$event->reason : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'goods_receipt.disputed',
                title: 'Delivery disputed: '.$receipt->number.($order ? ' on '.$order->number : ''),
                body: 'A dispute has been raised'.$with.' over '.$receipt->number.'.'.$reason,
                actionUrl: route('goods-receipts.show', $receipt, absolute: false),
            ),
            audience: $audience,
            subject: $receipt,
            causer: $this->resolveActor($event->actorId, $receipt),
            projectId: $order?->project_id !== null ? (int) $order->project_id : null,
        );
    }
}
