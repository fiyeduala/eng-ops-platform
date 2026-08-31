<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\RfiAnswered;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An answer arrived (key `rfi.answered`).
 *
 * THE ANSWER TEXT IS NOT IN THE MESSAGE, and that is deliberate rather than lazy. An RFI answer is
 * frequently the most commercially sensitive sentence on the record — "yes, that was our error, do
 * it and we will pay" — and a notification body is copied into an email, a push payload and a
 * preview line, none of which the access engine can take back. The dispatcher's authorization
 * filter means only people who could open the record are told at all (§68), but a body that
 * carries the substance would still put it in places the record itself never reaches. The subject
 * line says an answer exists; the record says what it is.
 *
 * THE AUDIENCE IS THE PERSON WHO ASKED, plus anybody following. Not the project, not the
 * discipline: most RFIs are answered in a day and matter to two people, and a register that
 * notified forty of them per answer would be muted within a week — and `rfi.answered` is mandatory,
 * so the only mute available is the whole inbox.
 *
 * The impact flags ARE carried, because "answered, and it costs money" is the half of the message
 * that decides whether somebody reads it today or on Friday. A flag is not the amount.
 */
class NotifyRfiAnswered
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(RfiAnswered $event): void
    {
        $rfi = $event->rfi;

        $audience = Audience::followers($rfi);

        if ($rfi->raised_by !== null) {
            $audience = $audience->orUser((int) $rfi->raised_by);
        }

        $impact = match (true) {
            $rfi->cost_impact && $rfi->schedule_impact => ' It is flagged as affecting both cost and the programme.',
            (bool) $rfi->cost_impact => ' It is flagged as affecting cost.',
            (bool) $rfi->schedule_impact => ' It is flagged as affecting the programme.',
            default => '',
        };

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'rfi.answered',
                title: 'Answered: '.$rfi->number.' — '.$rfi->subject,
                body: $rfi->number.' has been answered.'.$impact,
                actionUrl: route('rfis.show', $rfi, absolute: false),
            ),
            audience: $audience,
            subject: $rfi,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $rfi),
            projectId: $rfi->project_id !== null ? (int) $rfi->project_id : null,
        );
    }
}
