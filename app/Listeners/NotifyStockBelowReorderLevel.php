<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\StockFellBelowReorderLevel;
use App\Listeners\Concerns\ResolvesActor;
use App\Support\Quantity;

/**
 * The store is running out of something (key `stock.below_reorder_level`).
 *
 * IT IS OFF WHERE THE COMPANY SAYS SO. `inventory.notify_below_reorder_level` is a per-organization
 * setting and it is read here rather than at the point the event is raised: the crossing is a fact
 * about the ledger and it stays true whether or not anybody wants telling about it, so the event
 * always fires and only the notice is configurable. A company that runs its stores off a weekly
 * report turns this off and loses nothing else.
 *
 * THE AUDIENCE IS THE KEEPER, NOT THE BUYER. It is the storekeeper who reorders, or who says the
 * shelf is nearly empty; sending it to procurement would be telling somebody about a shortage they
 * cannot act on until a requisition exists. Followers of the store and of the catalogue item are
 * added because somebody who asked to watch a critical spare has said exactly what they want.
 *
 * THE SUBJECT IS THE BALANCE, which is the record actually being disclosed — an item, a store and a
 * figure. The dispatcher asks whether each recipient may view it, so a person who cannot open that
 * store's stock is told nothing rather than told a quantity (§68).
 */
class NotifyStockBelowReorderLevel
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly SettingsRepository $settings,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(StockFellBelowReorderLevel $event): void
    {
        $balance = $event->balance;

        if (! $this->settings->bool('inventory.notify_below_reorder_level', true, $balance->organization_id)) {
            return;
        }

        $balance->loadMissing('warehouse', 'stockItem');

        $warehouse = $balance->warehouse;
        $item = $balance->stockItem;

        if ($warehouse === null || $item === null) {
            return;
        }

        $audience = Audience::followers($balance)
            ->orFollowers($warehouse)
            ->orFollowers($item);

        if ($warehouse->keeper_id !== null) {
            $audience = $audience->orUser((int) $warehouse->keeper_id);
        }

        $onHand = Quantity::withUnit($balance->available(), $item->uom);
        $level = Quantity::withUnit($event->level, $item->uom);

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'stock.below_reorder_level',
                title: $item->name.' is down to '.$onHand.' at '.$warehouse->name,
                // The movement that caused it is named, so the first question — "what happened?" —
                // is answered without opening anything.
                body: $onHand.' of '.$item->name.' ('.$item->code.') is available at '.$warehouse->name
                    .' after '.$event->transaction->number.'. The reorder level is '.$level.'.',
                actionUrl: route('stock-items.show', $item, absolute: false),
            ),
            audience: $audience,
            subject: $balance,
            causer: $this->resolveActor($event->actorId, $balance),
            projectId: null,
        );
    }
}
