<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\ApprovalDecided;
use App\Listeners\Concerns\ResolvesActor;
use Illuminate\Database\Eloquent\Model;

/**
 * Tell the record's owner that somebody has decided (key `approval.decided`).
 *
 * ONE recipient: the owner. Not the other assignees — they see the stage close in their own inbox,
 * and telling three reviewers that a fourth agreed with them is noise that makes the useful
 * notifications harder to find. Not the department, for the same reason.
 *
 * The reviewer's COMMENT is quoted, and that is a considered exception to the usual rule against
 * putting content in a notification. The comment was written by the reviewer FOR the owner, it is
 * the entire actionable payload of a rejection, and the dispatcher has already confirmed the owner
 * may see the record it hangs off. Withholding it would make every rejection cost the author a
 * round trip to read one sentence (§68).
 */
class NotifyApprovalOutcome
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(ApprovalDecided $event): void
    {
        $subject = $event->stageInstance->instance?->subject;

        if (! $subject instanceof Model || ! method_exists($subject, 'workflowOwnerId')) {
            return;
        }

        $ownerId = $subject->workflowOwnerId();

        // Nobody needs telling they decided something themselves. The dispatcher's except($causer)
        // would catch it anyway; checking here saves building an audience of one that gets dropped.
        if ($ownerId === null || $ownerId === (int) $event->approval->user_id) {
            return;
        }

        $decision = $event->approval->resolvedDecision();

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'approval.decided',
                title: $decision->label().': '.$subject->workflowLabel(),
                body: $this->body($event),
                actionUrl: null,
            ),
            audience: Audience::user([$ownerId]),
            subject: $subject,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $subject),
            projectId: $subject->getAttribute('project_id') !== null
                ? (int) $subject->getAttribute('project_id')
                : null,
        );
    }

    private function body(ApprovalDecided $event): string
    {
        $approval = $event->approval;
        $approval->loadMissing('user');

        $body = ($approval->user?->name ?? 'A reviewer')
            .' recorded '.$approval->resolvedDecision()->label()
            .' at the "'.$event->stageInstance->displayName().'" stage.';

        if (filled($approval->comment)) {
            $body .= ' They said: '.mb_substr((string) $approval->comment, 0, 400);
        }

        return $body;
    }
}
