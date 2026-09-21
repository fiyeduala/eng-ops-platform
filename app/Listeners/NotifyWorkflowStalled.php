<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\WorkflowStalled;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;

/**
 * Tell whoever can fix it that a route has stopped (key `workflow.stalled`).
 *
 * This listener is what pays for the engine's decision to halt rather than skip. Halting is only
 * defensible if somebody hears about it; an unannounced stall is a slower version of the record
 * disappearing.
 *
 * The audience is defined by a PERMISSION — holders of `approval.reassign` — never by a role called
 * "Administrator". §5 gives companies their own role names, so a hard-coded one would silently
 * notify nobody in a tenant that named theirs differently, and the entire point of this message is
 * to break a silence.
 *
 * The owner is included: they are the person whose work has stopped, and the one most likely to
 * chase it.
 */
class NotifyWorkflowStalled
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(WorkflowStalled $event): void
    {
        $subject = $event->instance->subject;

        if (! $subject instanceof Model) {
            return;
        }

        $ids = $this->holdersOf('approval.reassign');

        if (method_exists($subject, 'workflowOwnerId') && $subject->workflowOwnerId() !== null) {
            $ids[] = $subject->workflowOwnerId();
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return;
        }

        $label = method_exists($subject, 'workflowLabel')
            ? $subject->workflowLabel()
            : class_basename($subject).' #'.$subject->getKey();

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'workflow.stalled',
                title: 'An approval route stalled: '.$label,
                body: 'The "'.$event->stageInstance->displayName().'" stage could not be given to anybody. '
                    .trim((string) $event->stageInstance->resolution_note)
                    .' The record has stopped and the stage needs reassigning.',
                actionUrl: route('approvals.show', $event->stageInstance, absolute: false),
            ),
            audience: Audience::user($ids),
            subject: $subject,
            requiredAbility: 'view',
        );
    }

    /** @return array<int,int> */
    private function holdersOf(string $permission): array
    {
        return Role::query()
            ->active()
            // `permissions.key`, qualified — see EscalationService::holdersOf(). An unqualified
            // `name` resolves to `roles.name` here and notifies nobody without erroring.
            ->whereHas('permissions', fn ($q) => $q->where('permissions.key', $permission))
            ->with('users:id')
            ->get()
            ->flatMap(fn (Role $role) => $role->users->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
