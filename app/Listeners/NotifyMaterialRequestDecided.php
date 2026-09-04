<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MaterialRequestDecided;
use App\Listeners\Concerns\ResolvesActor;

/**
 * The store answered (key `material_request.decided`).
 *
 * IT SAYS SO WHEN A LINE WAS CUT. Approval on a store request is routinely partial, and "approved"
 * on its own would let a foreman turn up expecting two hundred metres of something there are eighty
 * of. The count of shortened lines is computed from the request's own lines rather than passed in,
 * so the notice cannot disagree with the page it links to.
 *
 * THE QUANTITIES THEMSELVES ARE NOT IN THE BODY, only how many lines were affected. The recipient
 * is the requester and can open the record; anybody else on the audience is filtered by the
 * dispatcher's view check, and a number that survived that filter would still be a number in an
 * email inbox (§68).
 */
class NotifyMaterialRequestDecided
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MaterialRequestDecided $event): void
    {
        $request = $event->materialRequest;
        $request->loadMissing('items', 'status');

        $key = $request->status?->key;

        $verb = match ($key) {
            'approved' => 'approved',
            'rejected' => 'refused',
            'draft' => 'returned to you',
            default => 'answered',
        };

        $cut = $request->items
            ->filter(fn ($line) => $line->quantity_approved !== null
                && (float) $line->quantity_approved < (float) $line->quantity_requested)
            ->count();

        $shortfall = $cut > 0
            ? ' '.$cut.' '.($cut === 1 ? 'line was' : 'lines were').' approved for less than was asked for.'
            : '';

        $reason = ($key === 'rejected' && filled($request->decision_notes))
            ? ' Reason: '.$request->decision_notes
            : '';

        $audience = Audience::followers($request);

        foreach ([$request->created_by, $request->submitted_by] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'material_request.decided',
                title: 'Store request '.$verb.': '.$request->number,
                body: $request->number.' — '.$request->title.' — has been '.$verb.'.'.$shortfall.$reason,
                actionUrl: route('material-requests.show', $request, absolute: false),
            ),
            audience: $audience,
            subject: $request,
            causer: $this->resolveActor($event->actorId, $request),
            projectId: $request->project_id !== null ? (int) $request->project_id : null,
        );
    }
}
