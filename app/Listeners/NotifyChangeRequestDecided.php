<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\ChangeRequestDecided;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A change was answered (key `change_request.decided`).
 *
 * THIS ONE DOES GO TO THE PROJECT, and it is the only engineering notification that does. A change
 * to scope is not a private exchange between the person who raised it and the person who signed it:
 * it moves what everybody on that project is building. A team that finds out at the next progress
 * meeting has already built a week of the old design.
 *
 * THE MONEY IS NOT IN THE MESSAGE. The disposition is — approved, rejected, deferred, withdrawn —
 * because that is the fact people need and it is the same fact the register shows them. The cost is
 * not, for two reasons: half the project team can see the change but has no business seeing its
 * commercial value, and the dispatcher filters on `view` over the record rather than on any finer
 * grain than that (§68). The record shows the figure to whoever opens it; the email does not carry
 * it into an inbox that was never scoped.
 */
class NotifyChangeRequestDecided
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(ChangeRequestDecided $event): void
    {
        $change = $event->changeRequest;

        $audience = Audience::followers($change);

        if ($change->raised_by !== null) {
            $audience = $audience->orUser((int) $change->raised_by);
        }

        if ($change->project_id !== null) {
            $audience = $audience->orProject((int) $change->project_id);
        }

        $disposition = $change->disposition?->label() ?? 'Decided';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'change_request.decided',
                title: $disposition.': '.$change->number.' — '.$change->title,
                body: $change->number.' has been '.strtolower($disposition).'.'
                    .($change->changesTheContract()
                        ? ' The scope changes — check what this means for work already in hand.'
                        : ''),
                actionUrl: route('change-requests.show', $change, absolute: false),
            ),
            audience: $audience,
            subject: $change,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $change),
            projectId: $change->project_id !== null ? (int) $change->project_id : null,
        );
    }
}
