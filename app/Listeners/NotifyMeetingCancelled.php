<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MeetingCancelled;
use App\Listeners\Concerns\ResolvesActor;
use App\Support\MeetingNarrative;

/**
 * The meeting is off (key `meeting.cancelled`).
 *
 * Catalogued as `urgent`, the only key in the communication group that is, because its entire value
 * is in arriving before somebody sets off. Urgent bypasses quiet hours, and that is the right trade
 * at seven in the morning for a meeting at eight.
 *
 * Sent to the organizer as well as the participants — the person who arranged it is usually the
 * person who cancelled it and is therefore excluded as the causer anyway, but when an administrator
 * cancels on their behalf the organizer is precisely who must not find out by turning up.
 *
 * The reason is included. It is entered against a transition the state machine marks as requiring a
 * comment, so it is a deliberate sentence rather than free-form record content, and "cancelled" with
 * no reason is the single most-asked follow-up question there is.
 */
class NotifyMeetingCancelled
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MeetingCancelled $event): void
    {
        $meeting = $event->meeting;

        $recipientIds = $meeting->participants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($meeting->organizer_id !== null) {
            $recipientIds[] = (int) $meeting->organizer_id;
        }

        $recipientIds = array_values(array_unique($recipientIds));

        if ($recipientIds === []) {
            return;
        }

        $message = NotificationMessage::make(
            key: 'meeting.cancelled',
            title: 'Cancelled: '.$meeting->title,
            body: $this->body($event),
            actionUrl: route('meetings.show', $meeting, absolute: false),
        );

        $this->dispatcher->dispatch(
            notification: $message,
            audience: Audience::user($recipientIds),
            subject: $meeting,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $meeting),
        );
    }

    private function body(MeetingCancelled $event): string
    {
        $line = MeetingNarrative::whenAndWhere($event->meeting).' is cancelled.';

        return $event->reason !== null ? $line.' '.$event->reason : $line;
    }
}
