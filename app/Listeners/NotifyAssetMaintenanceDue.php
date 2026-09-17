<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\AssetMaintenanceDue;

/**
 * A planned service is coming due (key `asset_maintenance.due`).
 *
 * NO CAUSER, and this is the only listener in the part with none. The event is raised by a
 * scheduled sweep rather than by a person, so there is nobody to exclude from the audience and
 * nobody to name in the audit trail. Attributing the notice to whoever's cron account ran the
 * scheduler would put a name against a decision that name did not make.
 *
 * THE CUSTODIAN IS TOLD, NOT ONLY THE PLANNER. Whoever has the machine is the person who has to
 * stop using it and bring it in; the planner already knows, because they scheduled it. That is why
 * the audience reaches through the maintenance record to the ASSET's current custodian rather than
 * stopping at `performed_by` — a job that has not been allocated to a fitter yet has no
 * `performed_by` at all, and those are exactly the ones worth chasing.
 *
 * WORDING TURNS ON THE SIGN OF `daysRemaining`. "Due in 7 days" and "overdue by 3 days" are the same
 * event and opposite news, and a body that said "due in -3 days" would be read as a bug rather than
 * as a machine running past its inspection.
 */
class NotifyAssetMaintenanceDue
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(AssetMaintenanceDue $event): void
    {
        $maintenance = $event->maintenance;
        $maintenance->loadMissing('asset');

        $asset = $maintenance->asset;

        if ($asset === null) {
            return;
        }

        $audience = Audience::followers($maintenance)->orFollowers($asset);

        foreach ([$maintenance->performed_by, $maintenance->created_by, $asset->custodian_id] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        if ($asset->org_unit_id !== null) {
            $audience = $audience->orOrgUnit((int) $asset->org_unit_id);
        }

        $days = $event->daysRemaining;

        $when = match (true) {
            $days < 0 => 'is overdue by '.abs($days).' '.($days === -1 ? 'day' : 'days'),
            $days === 0 => 'is due today',
            default => 'is due in '.$days.' '.($days === 1 ? 'day' : 'days'),
        };

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'asset_maintenance.due',
                title: 'Maintenance '.($days < 0 ? 'overdue' : 'due').': '.$asset->displayName(),
                body: $maintenance->title.' on '.$asset->displayName().' '.$when.'.',
                actionUrl: route('asset-maintenances.show', $maintenance, absolute: false),
            ),
            audience: $audience,
            subject: $maintenance,
            projectId: $asset->project_id === null ? null : (int) $asset->project_id,
        );
    }
}
