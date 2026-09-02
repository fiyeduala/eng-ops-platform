<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PurchaseRequestSubmitted;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A requisition is waiting for a decision (key `purchase_request.submitted`).
 *
 * THE ABILITY DOES THE AIMING, NOT THE AUDIENCE. The candidates are drawn widely — the head of the
 * requester's unit, their supervisor, anybody following the record — and then filtered to the people
 * who actually hold `approve` over this requisition. Written the other way round it would either
 * miss the delegate who was given approval last week or spam a department with somebody else's work.
 *
 * NO MONEY IN THE BODY. The estimated total sits on a record with its own confidentiality; what
 * this says is what is needed to decide whether to open it — what it is for, and how soon.
 */
class NotifyPurchaseRequestSubmitted
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PurchaseRequestSubmitted $event): void
    {
        $request = $event->purchaseRequest;

        $audience = Audience::followers($request);

        if ($request->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $request->org_unit_id);
        }

        if ($request->created_by !== null) {
            $audience = $audience->orSupervisorOf((int) $request->created_by);
        }

        $urgency = in_array($request->priority, ['high', 'urgent'], true)
            ? ' Marked '.$request->priority.'.'
            : '';

        $wanted = $request->required_by !== null
            ? ' Wanted by '.$request->required_by->format('j M Y').'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'purchase_request.submitted',
                title: 'Requisition awaiting approval: '.$request->number.' — '.$request->title,
                body: $request->number.' has been submitted for approval.'.$urgency.$wanted,
                actionUrl: route('purchase-requests.show', $request, absolute: false),
            ),
            audience: $audience,
            subject: $request,
            // Only somebody who can actually answer it should be told it is waiting on them.
            requiredAbility: 'approve',
            causer: $this->resolveActor($event->actorId, $request),
            projectId: $request->project_id !== null ? (int) $request->project_id : null,
        );
    }
}
