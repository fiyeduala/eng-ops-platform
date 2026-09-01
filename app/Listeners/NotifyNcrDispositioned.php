<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\NcrDispositioned;
use App\Listeners\Concerns\ResolvesActor;

/**
 * The engineering answer has been given (key `ncr.dispositioned`).
 *
 * THE DISPOSITION IS NAMED AND ITS JUSTIFICATION IS NOT. "Use as is" on a structural element is a
 * decision people read a great deal into, and the reasoning NcrService insists on is exactly the
 * material that should be read in context rather than skimmed in a preview line. Naming the
 * disposition is necessary — it is what the message is for; quoting the justification is not.
 *
 * EVERYBODY WHO TOUCHED THE NCR IS TOLD: the raiser, whoever responded, and anybody following. The
 * person who dispositioned it is dropped by the dispatcher's `except(causer)` — nobody needs telling
 * about their own decision.
 */
class NotifyNcrDispositioned
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(NcrDispositioned $event): void
    {
        $ncr = $event->ncr;

        $parties = array_values(array_unique(array_filter([
            $ncr->detected_by,
            $ncr->created_by,
            $ncr->responded_by,
        ], fn ($id) => $id !== null)));

        $audience = Audience::user($parties)->orFollowers($ncr);

        $disposition = filled($ncr->disposition)
            ? str_replace('_', ' ', (string) $ncr->disposition)
            : 'recorded';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'ncr.dispositioned',
                title: 'NCR dispositioned: '.$ncr->number.' — '.$disposition,
                body: $ncr->number.' has been dispositioned as '.$disposition
                    .'. The reasoning and any conditions are on the record.',
                actionUrl: route('ncrs.show', $ncr, absolute: false),
            ),
            audience: $audience,
            subject: $ncr,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $ncr),
            projectId: $ncr->project_id !== null ? (int) $ncr->project_id : null,
        );
    }
}
