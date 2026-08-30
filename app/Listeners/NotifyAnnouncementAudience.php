<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\AnnouncementPublished;
use App\Listeners\Concerns\ResolvesActor;
use App\Models\Announcement;

/**
 * Put a published notice in front of the part of the company it is addressed to (key
 * `announcement.published`).
 *
 * This is the one listener whose audience is genuinely large, and the one place where the
 * distinction the Announcement model documents has to be got right in code:
 *
 *   ADDRESSING is routing. It answers "who is this for" and it reads UP — a notice pinned to
 *   Engineering is for everyone in Engineering including its sub-units, and a notice with neither a
 *   unit nor a project is for the whole company.
 *
 *   AUTHORIZATION is reach. It answers "who may see this" and it reads DOWN, and it is not decided
 *   here at all: every id this audience produces is put through AnnouncementPolicy by the dispatcher
 *   before a row is written.
 *
 * Building the audience from the addressing columns and letting the filter do the rest is what keeps
 * those two from being confused. The alternative — deriving the audience from who can see it — would
 * mean a director who can see every notice in the company gets notified about all of them.
 *
 * Email is off by default for this key in the catalogue. Announcements are the highest-volume, least
 * time-critical thing the system sends, and an inbox full of them is how people learn to ignore the
 * ones that matter.
 */
class NotifyAnnouncementAudience
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(AnnouncementPublished $event): void
    {
        $announcement = $event->announcement;

        // A notice published already expired is a correction of the record, not news.
        if ($announcement->hasExpired()) {
            return;
        }

        // Explicit, because the event carries whatever the controller happened to have loaded and
        // strict mode turns an unloaded relation into a thrown exception mid-publish.
        $announcement->loadMissing(['project', 'orgUnit']);

        $message = NotificationMessage::make(
            key: 'announcement.published',
            title: $announcement->title,
            body: $this->body($announcement),
            actionUrl: route('announcements.show', $announcement, absolute: false),

            // The author's own urgency, which is exactly the case NotificationMessage's override
            // exists for: the same key is a staff-notice most weeks and a site-closure this week.
            // An unrecognised value is discarded by NotificationMessage::urgency(), so a bad row
            // degrades to normal rather than escaping quiet hours.
            urgency: $announcement->urgency,
        );

        $this->dispatcher->dispatch(
            notification: $message,
            audience: $this->audienceFor($announcement),
            subject: $announcement,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $announcement),
        );
    }

    /**
     * Narrowest addressing wins.
     *
     * A notice carrying both a project and a unit is for that project's people in that discipline —
     * the union would be wrong in the direction that matters, since it would put a site-specific
     * notice in front of the whole department.
     */
    private function audienceFor(Announcement $announcement): Audience
    {
        if ($announcement->project_id !== null && $announcement->org_unit_id !== null) {
            return Audience::projectDepartment((int) $announcement->project_id, (int) $announcement->org_unit_id);
        }

        if ($announcement->project_id !== null) {
            return Audience::project((int) $announcement->project_id);
        }

        if ($announcement->org_unit_id !== null) {
            return Audience::orgUnit((int) $announcement->org_unit_id, includeSubtree: true);
        }

        return Audience::organization();
    }

    /**
     * Deliberately not an extract of the body.
     *
     * The body is long-form free text somebody typed, and a truncated first paragraph is the classic
     * way half a sentence about a redundancy round ends up in a mail preview. What the reader needs
     * to triage the notice is where it came from and how long it stands — both structured fields.
     */
    private function body(Announcement $announcement): ?string
    {
        $parts = array_filter([
            $announcement->audienceLabel(),
            $announcement->expires_on !== null ? 'Until '.$announcement->expires_on->format('j M Y') : null,
        ]);

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
