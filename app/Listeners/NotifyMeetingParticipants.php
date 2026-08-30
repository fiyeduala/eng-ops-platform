<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MeetingInvited;
use App\Listeners\Concerns\ResolvesActor;
use App\Support\MeetingNarrative;

/**
 * Tell somebody they are expected somewhere (key `meeting.invited`).
 *
 * Only the newly invited are told — MeetingService::invite() returns exactly those ids and the event
 * carries only those. Re-announcing the meeting to the whole room every time one more person is
 * added is how a well-meaning invitation list turns into noise people filter, which then costs the
 * next genuinely new invitee their notice.
 *
 * The body carries when and where, because those are the two facts that decide whether the recipient
 * has to do anything before they open the system, and both are already on an invitation by nature.
 * The agenda is not quoted: it is free text, and for a restricted meeting the dispatcher would strip
 * it anyway — better that the rule is the same at both confidentiality levels than that the body
 * quietly changes shape.
 */
class NotifyMeetingParticipants
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MeetingInvited $event): void
    {
        $meeting = $event->meeting;

        if ($event->userIds === [] || $meeting->isCancelled()) {
            return;
        }

        $message = NotificationMessage::make(
            key: 'meeting.invited',
            title: 'Meeting: '.$meeting->title,
            body: MeetingNarrative::whenAndWhere($meeting),
            actionUrl: route('meetings.show', $meeting, absolute: false),
        );

        $this->dispatcher->dispatch(
            notification: $message,
            audience: Audience::user($event->userIds),
            subject: $meeting,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $meeting),
        );
    }
}
