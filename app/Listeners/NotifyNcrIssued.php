<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\NcrIssued;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A non-conformance has been issued (key `ncr.issued`).
 *
 * THE RESPONSE DATE IS THE MESSAGE. An NCR that is issued and then sits unanswered is the single
 * most common failure of a quality register, and the date by which a response is required is the
 * only part of it anybody needs to act on today.
 *
 * THE FINDING ITSELF STAYS ON THE RECORD. `requirement` is the clause that was breached and it is
 * frequently the sentence a commercial argument later turns on — it belongs where it can be read in
 * full, with the response and the disposition next to it, not in a push preview (§68).
 *
 * Notifying the subcontractor or supplier the finding is against is not something this listener can
 * do: `party_name` is free text until Phase 5 gives external parties records of their own, and the
 * dispatcher only delivers to users of this organization.
 */
class NotifyNcrIssued
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(NcrIssued $event): void
    {
        $ncr = $event->ncr;

        $audience = Audience::followers($ncr);

        if ($ncr->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $ncr->org_unit_id);
        }

        if ($ncr->detected_by !== null) {
            $audience = $audience->orUser((int) $ncr->detected_by);
        }

        $due = $ncr->required_by !== null
            ? ' A response is required by '.$ncr->required_by->format('j M Y').'.'
            : '';

        $severity = filled($ncr->severity)
            ? ' Severity: '.str_replace('_', ' ', (string) $ncr->severity).'.'
            : '';

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'ncr.issued',
                title: 'NCR issued: '.$ncr->number.' — '.$ncr->title,
                body: $ncr->number.' has been issued.'.$severity.$due,
                actionUrl: route('ncrs.show', $ncr, absolute: false),
            ),
            audience: $audience,
            subject: $ncr,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $ncr),
            projectId: $ncr->project_id !== null ? (int) $ncr->project_id : null,
        );
    }
}
