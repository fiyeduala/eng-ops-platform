<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\IncidentReported;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An incident has been reported (key `hse_incident.reported`).
 *
 * THE BODY SAYS WHAT HAPPENED, WHERE, AND HOW BAD — AND NOTHING ELSE. No name, no injury, no body
 * part, no treatment. Those live on `incident_persons` behind IncidentPersonPolicy::viewMedical(),
 * a second question layered on top of `view`, and a notification body is copied into an email, a
 * push payload and a preview line that the access engine cannot take back (§68). The dispatcher
 * already refuses to deliver to anybody who cannot open the record; this listener makes sure the
 * message is safe even for the people who can.
 *
 * THE AUDIENCE IS DELIBERATELY NARROW: whoever is following, and the line management above the unit
 * the incident belongs to. Both are drawn from THIS company's own org structure rather than from a
 * role slug this application invented — a listener that notified `hse-manager` would be one firm's
 * job titles compiled into the source, and the firms that rename or split that role would silently
 * get nothing. Telling the wider HSE department is exactly what the workflow engine's `notify`
 * auto-action is for, configured per company on whichever status matters to them (§32, §73).
 */
class NotifyIncidentReported
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(IncidentReported $event): void
    {
        $incident = $event->incident;

        $audience = Audience::followers($incident);

        if ($incident->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $incident->org_unit_id);
        }

        if ($incident->reported_by !== null) {
            $audience = $audience->orSupervisorOf((int) $incident->reported_by);
        }

        $where = filled($incident->location) ? ' at '.$incident->location : '';
        $severity = filled($incident->severity)
            ? ' Severity: '.str_replace('_', ' ', (string) $incident->severity).'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'hse_incident.reported',
                title: 'Incident reported: '.$incident->number.' — '.$incident->title,
                body: $incident->number.' has been reported'.$where.'.'.$severity
                    .' Open the record for the detail.',
                actionUrl: route('incidents.show', $incident, absolute: false),
            ),
            audience: $audience,
            subject: $incident,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $incident),
            projectId: $incident->project_id !== null ? (int) $incident->project_id : null,
        );
    }
}
