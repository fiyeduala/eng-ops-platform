<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\VehicleDocumentExpiring;

/**
 * A vehicle document is about to lapse (key `vehicle_document.expiring`).
 *
 * THE ONLY URGENT NOTIFICATION IN THIS PART, and the only one that reaches for push as well as
 * e-mail. The reason is legal rather than operational: a truck whose insurance ran out last week is
 * a truck that must not leave the yard, and the exposure if it does is not a maintenance backlog
 * but an uninsured collision with somebody else's car in it.
 *
 * THE DRIVER IS TOLD AS WELL AS THE FLEET MANAGER. The manager renews it; the driver is the person
 * who will be stopped at a checkpoint holding a lapsed certificate, and telling only the office is
 * how that happens. `fleet-manager` reaches this through the role audience, the assigned driver by
 * name, and both are still put through the five gates below (§68).
 *
 * NO FILE, NO NUMBER, NO LINK TO THE SCAN — the body names the document type and the plate and
 * nothing else. The certificate itself lives in private storage behind a download that re-checks
 * authorization every time, and a notification is delivered to more inboxes than a page is opened
 * by (§67).
 */
class NotifyVehicleDocumentExpiring
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(VehicleDocumentExpiring $event): void
    {
        $document = $event->document;
        $document->loadMissing('vehicle.asset');

        $vehicle = $document->vehicle;

        if ($vehicle === null) {
            return;
        }

        $audience = Audience::followers($document)
            ->orFollowers($vehicle)
            ->orRole('fleet-manager');

        foreach ([$vehicle->assigned_driver_id, $vehicle->asset?->custodian_id, $document->created_by] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        $days = $event->daysRemaining;

        $when = match (true) {
            $days < 0 => 'expired '.abs($days).' '.($days === -1 ? 'day' : 'days').' ago',
            $days === 0 => 'expires today',
            default => 'expires in '.$days.' '.($days === 1 ? 'day' : 'days'),
        };

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'vehicle_document.expiring',
                title: ($days < 0 ? 'Expired' : 'Expiring').': '.$document->typeLabel()
                    .' on '.$vehicle->registration_number,
                body: 'The '.mb_strtolower($document->typeLabel()).' for '
                    .$vehicle->displayName().' '.$when.'.',
                actionUrl: route('vehicle-documents.show', $document, absolute: false),
            ),
            audience: $audience,
            subject: $document,
        );
    }
}
