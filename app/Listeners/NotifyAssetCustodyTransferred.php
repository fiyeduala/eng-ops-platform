<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\AssetCustodyTransferred;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An asset changed hands (key `asset.custody_transferred`).
 *
 * BOTH ENDS ARE TOLD, WHICH IS THE POINT. The person receiving the machine is now answerable for
 * it; the person handing it over needs the same message as their evidence that they did. A
 * notification that went only to the recipient would leave the previous custodian discovering, at
 * the next stocktake, that a thing they gave away three months ago is still against their name.
 *
 * THE AUTHORISER IS INCLUDED for the same reason a countersignature exists: they are on the record,
 * and being on a record you were never shown is how an authorisation becomes a formality.
 *
 * THE SUBJECT IS THE ASSET, NOT THE TRANSFER, and that is deliberate. `AssetCustodyTransferPolicy`
 * and `AssetPolicy` both answer to `asset.view`, so either would gate identically — but the action
 * link goes to the asset, and a notification whose authorization check and whose link disagree is
 * one that can be delivered to somebody who then gets a 403 opening it. Asking about the thing the
 * reader is being sent to keeps those two answers the same (§68).
 *
 * NO MONEY IN THE BODY. Purchase cost is on the asset and plenty of people who must know a
 * generator is now theirs have no business knowing what the company paid for it.
 */
class NotifyAssetCustodyTransferred
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(AssetCustodyTransferred $event): void
    {
        $transfer = $event->transfer;
        $transfer->loadMissing('asset');

        $asset = $transfer->asset;

        if ($asset === null) {
            return;
        }

        $audience = Audience::followers($asset);

        foreach ([$transfer->to_custodian_id, $transfer->from_custodian_id, $transfer->authorised_by] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        // The receiving department and the receiving project, where there is one: a site manager
        // whose project has just been given a compressor has a reason to know it arrived, and the
        // five gates below still decide whether any individual among them may actually be told.
        if ($transfer->to_org_unit_id !== null) {
            $audience = $audience->orOrgUnit((int) $transfer->to_org_unit_id);
        }

        if ($transfer->to_project_id !== null) {
            $audience = $audience->orProject((int) $transfer->to_project_id);
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'asset.custody_transferred',
                title: 'Custody changed: '.$asset->displayName(),
                body: $asset->displayName().' moved from '.$transfer->fromLabel()
                    .' to '.$transfer->toLabel().'.'
                    .(filled($transfer->reason) ? ' '.$transfer->reason : ''),
                actionUrl: route('assets.show', $asset, absolute: false),
            ),
            audience: $audience,
            subject: $asset,
            causer: $this->resolveActor($transfer->created_by === null ? null : (int) $transfer->created_by, $asset),
            projectId: $transfer->to_project_id === null ? null : (int) $transfer->to_project_id,
        );
    }
}
