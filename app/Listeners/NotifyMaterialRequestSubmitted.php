<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MaterialRequestSubmitted;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A store request is waiting for a decision (key `material_request.submitted`).
 *
 * THE KEEPER OF THE NAMED STORE IS THE ONE ADDITION over the requisition version. A material
 * request usually names the store it wants collecting from, and that store's keeper is the person
 * who will be asked for it whether or not they are the approver — so they are drawn into the
 * candidate list and then filtered like everybody else.
 *
 * THE ABILITY DOES THE AIMING. Candidates are gathered widely and narrowed to whoever actually
 * holds `approve` over this request, so a delegate given the grant last week is included and a
 * department that merely sits nearby is not.
 *
 * NO QUANTITIES IN THE BODY. What the store is holding is a separate question behind a separate
 * grant (§68); this says what is being asked for and by when, which is what decides whether to
 * open it.
 */
class NotifyMaterialRequestSubmitted
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MaterialRequestSubmitted $event): void
    {
        $request = $event->materialRequest;

        /*
         * THE WHOLE ROW, NOT THE THREE COLUMNS THIS LISTENER NEEDS.
         *
         * A listener runs synchronously, inside the transaction of the service that fired it, and
         * `loadMissing()` attaches what it loads to the caller's own instance. Ask for
         * `warehouse:id,name,keeper_id` here and the service that submitted this request goes on
         * holding a Warehouse with no `status_id` on it — so the next question anybody asks that
         * store ("are you open?") is answered from a column that was never selected, and a store
         * that has been open for a month reads as never used. That is how a notification silently
         * broke an issue three method calls later.
         *
         * Trimming a single row saves nothing worth having. Where a listener must load a relation
         * onto the event's own record, it loads the record as the rest of the application would
         * see it.
         */
        $request->loadMissing('warehouse');

        $audience = Audience::followers($request);

        if ($request->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $request->org_unit_id);
        }

        if ($request->created_by !== null) {
            $audience = $audience->orSupervisorOf((int) $request->created_by);
        }

        if ($request->warehouse?->keeper_id !== null) {
            $audience = $audience->orUser((int) $request->warehouse->keeper_id);
        }

        $wanted = $request->required_by !== null
            ? ' Wanted by '.$request->required_by->format('j M Y').'.'
            : '';

        $from = $request->warehouse !== null ? ' from '.$request->warehouse->name : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'material_request.submitted',
                title: 'Store request awaiting approval: '.$request->number.' — '.$request->title,
                body: $request->number.' has been submitted'.$from.'.'.$wanted,
                actionUrl: route('material-requests.show', $request, absolute: false),
            ),
            audience: $audience,
            subject: $request,
            requiredAbility: 'approve',
            causer: $this->resolveActor($event->actorId, $request),
            projectId: $request->project_id !== null ? (int) $request->project_id : null,
        );
    }
}
