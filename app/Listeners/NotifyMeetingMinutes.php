<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MeetingMinutesCirculated;
use App\Listeners\Concerns\ResolvesActor;

/**
 * The minutes have been released (key `meeting.minutes_published`).
 *
 * Fires on circulation, never on recording. Meeting::minutesVisibleTo() withholds a draft from the
 * room until it is circulated, and notifying on the write would announce the existence of a document
 * the recipients would then be refused — the exact pattern §68 forbids, and the more annoying for
 * being a 403 on a link the system itself sent.
 *
 * Not one word of the minutes appears in the message. The whole point of the field-level rule is
 * that the minutes are the sensitive part of an otherwise ordinary record; quoting an extract into
 * an email would route around it for the one channel that leaves the building.
 *
 * Participants only. Circulating minutes does not make them a company bulletin — anybody else with
 * reach over the meeting will find them on the record, having passed the same policy the dispatcher
 * would have applied.
 */
class NotifyMeetingMinutes
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MeetingMinutesCirculated $event): void
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
            key: 'meeting.minutes_published',
            title: 'Minutes circulated: '.$meeting->title,
            body: $this->body($meeting->decisions()->count(), $meeting->actions()->count()),
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

    /**
     * Counts, not contents.
     *
     * "3 decisions, 5 actions" tells a recipient whether they need to read it today without telling
     * anyone what was decided. The action count is drawn from the linked tasks rather than a column,
     * because actions ARE tasks here and a separate counter would drift the first time one is
     * cancelled.
     */
    private function body(int $decisions, int $actions): string
    {
        return sprintf(
            '%d decision%s, %d action%s recorded.',
            $decisions,
            $decisions === 1 ? '' : 's',
            $actions,
            $actions === 1 ? '' : 's',
        );
    }
}
