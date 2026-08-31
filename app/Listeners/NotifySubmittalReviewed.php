<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\SubmittalReviewed;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A package came back coded (key `submittal.reviewed`).
 *
 * THE CODE IS IN THE MESSAGE, unlike the RFI answer next door, and the difference is real. A review
 * code is a token from a contract's own vocabulary — "B", "2", "Approved as Noted" — that names the
 * verdict without disclosing the reasoning. The reviewer's comment, which is where the sensitive
 * material lives, is not carried. Somebody reading "Coded C" in an email knows to open the record;
 * they learn nothing from it that the record would not tell them.
 *
 * THE AUDIENCE IS THE SUBMITTING SIDE. The submitter, whoever opened the package, and anybody
 * following it. Not the reviewer's own people — they have just been in the room where this was
 * decided — and not the project at large, because a project of any size produces dozens of these a
 * week and a broadcast would bury the one that stops work.
 */
class NotifySubmittalReviewed
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(SubmittalReviewed $event): void
    {
        $submittal = $event->submittal;

        $recipients = array_values(array_unique(array_filter([
            $submittal->submitted_by === null ? null : (int) $submittal->submitted_by,
            $submittal->created_by === null ? null : (int) $submittal->created_by,
        ])));

        $audience = Audience::followers($submittal);

        if ($recipients !== []) {
            $audience = $audience->orUser($recipients);
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'submittal.reviewed',
                title: 'Coded '.$submittal->review_code.': '.$submittal->number,
                body: $submittal->number.' — '.$submittal->title
                    .' — came back coded "'.$submittal->review_code.'"'
                    .($submittal->party_name ? ' by '.$submittal->party_name : '').'.',
                actionUrl: route('submittals.show', $submittal, absolute: false),
            ),
            audience: $audience,
            subject: $submittal,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $submittal),
            projectId: $submittal->project_id !== null ? (int) $submittal->project_id : null,
        );
    }
}
