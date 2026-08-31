<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\ApprovalRequested;
use App\Listeners\Concerns\ResolvesActor;
use App\Models\WorkflowStageInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Tell the people a stage was given to that it is waiting on them (key `approval.requested`).
 *
 * The audience is the FROZEN assignee list and nothing else. Not the role's current holders, not
 * the department — the exact people the engine recorded at stage entry, so the notification and the
 * inbox can never disagree about who was asked.
 *
 * That list is then handed to the dispatcher, which re-asks the SUBJECT's policy for every
 * candidate. Not ceremony: the resolver picked these people off an org chart, and somebody can sit
 * on a role granting `approval.act` while having no reach at all to the confidential document that
 * just landed on it. The right outcome there is that they are not told and the stage eventually
 * escalates — not that a title leaks to somebody who cannot open the record (§68).
 *
 * The body says what is wanted and by when, and nothing about what the record contains.
 */
class NotifyApprovalAssignees
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(ApprovalRequested $event): void
    {
        $stageInstance = $event->stageInstance;
        $assigneeIds = $stageInstance->assigneeIds();

        if ($assigneeIds === []) {
            return;   // a stalled stage; WorkflowStalled tells the people who can fix it
        }

        $subject = $stageInstance->instance?->subject;

        if (! $subject instanceof Model) {
            return;
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'approval.requested',
                title: $this->title($stageInstance, $subject),
                body: $this->body($stageInstance),
                actionUrl: route('approvals.show', $stageInstance, absolute: false),
            ),
            audience: Audience::user($assigneeIds),
            subject: $subject,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $subject),
            projectId: $subject->getAttribute('project_id') !== null
                ? (int) $subject->getAttribute('project_id')
                : null,
        );
    }

    private function title(WorkflowStageInstance $stageInstance, Model $subject): string
    {
        $label = method_exists($subject, 'workflowLabel')
            ? $subject->workflowLabel()
            : class_basename($subject).' #'.$subject->getKey();

        return ucfirst($stageInstance->actionType()->verb()).': '.$label;
    }

    private function body(WorkflowStageInstance $stageInstance): string
    {
        $body = 'You have been asked to act at the "'.$stageInstance->displayName().'" stage.';

        if ($stageInstance->due_at !== null) {
            $body .= ' It is due '.$stageInstance->due_at->format('j M Y, H:i').'.';
        }

        return $body;
    }
}
