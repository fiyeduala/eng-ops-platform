<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\QualityInspectionRequested;
use App\Listeners\Concerns\ResolvesActor;

/**
 * "The rebar is ready — come and look before we pour" (key `quality_inspection.requested`).
 *
 * WORK IS STOPPED WHILE THIS IS OUTSTANDING, which is the whole reason it is worth an urgent-ish
 * notification at all, and why the body leads with when the inspection is needed rather than what it
 * is about. The gap between this message and the result is the number the register exists to
 * expose.
 *
 * The audience is whoever heads the unit the inspection sits under, plus followers. Which QA/QC
 * person actually attends is a company's own arrangement — a `notify` auto-action on the requested
 * status is where that belongs, not a role slug baked in here (§32).
 */
class NotifyQualityInspectionRequested
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(QualityInspectionRequested $event): void
    {
        $inspection = $event->inspection;

        $audience = Audience::followers($inspection);

        if ($inspection->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $inspection->org_unit_id);
        }

        if ($inspection->discipline_id !== null) {
            $audience = $audience->orHeadOf((int) $inspection->discipline_id);
        }

        $when = match (true) {
            $inspection->scheduled_for !== null => ' Scheduled for '.$inspection->scheduled_for->format('j M Y H:i').'.',
            $inspection->required_by !== null => ' Required by '.$inspection->required_by->format('j M Y').'.',
            default => '',
        };

        $where = filled($inspection->location) ? ' at '.$inspection->location : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'quality_inspection.requested',
                title: 'Inspection requested: '.$inspection->number.' — '.$inspection->title,
                body: $inspection->number.' has been requested'.$where.'.'.$when,
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
