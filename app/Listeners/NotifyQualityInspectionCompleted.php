<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\QualityInspectionCompleted;
use App\Listeners\Concerns\ResolvesActor;

/**
 * The verdict is in (key `quality_inspection.completed`).
 *
 * SENT FOR A PASS AS WELL AS A REJECTION. The requester has work standing still either way, and a
 * register that only speaks up about failures teaches everybody that silence means nothing has
 * happened — which is exactly when a passed hold point sits unnoticed for a day.
 *
 * The result IS in the body, unlike most notifications in this application. It is not sensitive, it
 * is the entire content of the message, and a body that said only "your inspection has a result"
 * would force a click to learn a single word.
 */
class NotifyQualityInspectionCompleted
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(QualityInspectionCompleted $event): void
    {
        $inspection = $event->inspection;

        $audience = Audience::followers($inspection);

        if ($inspection->created_by !== null) {
            $audience = $audience->orUser((int) $inspection->created_by);
        }

        $result = filled($inspection->result)
            ? str_replace('_', ' ', (string) $inspection->result)
            : 'recorded';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'quality_inspection.completed',
                title: 'Inspection '.$result.': '.$inspection->number.' — '.$inspection->title,
                body: $inspection->number.' has been inspected. Result: '.$result.'.'
                    .(filled($inspection->comments) ? ' Comments are on the record.' : ''),
                actionUrl: route('quality-inspections.show', $inspection, absolute: false),
            ),
            audience: $audience,
            subject: $inspection,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $inspection),
            projectId: $inspection->project_id !== null ? (int) $inspection->project_id : null,
        );
    }
}
