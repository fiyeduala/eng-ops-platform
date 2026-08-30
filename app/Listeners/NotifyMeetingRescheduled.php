<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MeetingRescheduled;
use App\Listeners\Concerns\ResolvesActor;
use App\Models\Meeting;
use App\Support\MeetingNarrative;

/**
 * The meeting moved (key `meeting.rescheduled`).
 *
 * Everybody on the participant list is told, including the people who had already sent apologies:
 * an apology is a statement about the OLD time, and treating it as a standing excusal is how
 * somebody misses the rearranged meeting they would have attended.
 *
 * The body says what it moved FROM as well as to. Without that, a recipient with two invitations in
 * a week cannot tell which one changed, and the notification costs them more time than it saves.
 */
class NotifyMeetingRescheduled
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MeetingRescheduled $event): void
    {
        $meeting = $event->meeting;

        if ($meeting->isCancelled()) {
            return;
        }

        $participantIds = $this->participantIds($meeting);

        if ($participantIds === []) {
            return;
        }

        $message = NotificationMessage::make(
            key: 'meeting.rescheduled',
            title: 'Moved: '.$meeting->title,
            body: $this->body($event),
            actionUrl: route('meetings.show', $meeting, absolute: false),
        );

        $this->dispatcher->dispatch(
            notification: $message,
            audience: Audience::user($participantIds),
            subject: $meeting,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $meeting),
        );
    }

    private function body(MeetingRescheduled $event): string
    {
        $now = MeetingNarrative::whenAndWhere($event->meeting);

        $was = MeetingNarrative::previously(
            $event->timeChanged() ? $event->previousStartsAt : null,
            $event->placeChanged() ? $event->previousLocation : null,
        );

        return $was !== null ? $now.' ('.$was.')' : $now;
    }

    /** @return array<int,int> */
    private function participantIds(Meeting $meeting): array
    {
        return $meeting->participants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
