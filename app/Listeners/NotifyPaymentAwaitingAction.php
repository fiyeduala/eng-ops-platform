<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\PaymentAwaitingAction;
use App\Listeners\Concerns\ResolvesActor;

/**
 * A payment is waiting to be approved or released (key `payment.awaiting_action`).
 *
 * The ability on the event does the aiming: after submission only people who may `approve` it are
 * told, after approval only people who may `pay` it. The payee and amount stay on the record.
 */
class NotifyPaymentAwaitingAction
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(PaymentAwaitingAction $event): void
    {
        $payment = $event->payment;
        $wanted = $event->ability === 'pay' ? 'to be released' : 'approval';

        $audience = Audience::followers($payment)->orRole('finance-manager')->orRole('finance-officer');

        if ($payment->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $payment->org_unit_id);
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'payment.awaiting_action',
                title: 'Payment awaiting '.$wanted.': '.$payment->number,
                body: $payment->number.' is awaiting '.$wanted.'.',
                actionUrl: route('payments.show', $payment, absolute: false),
            ),
            audience: $audience,
            subject: $payment,
            requiredAbility: $event->ability,
            causer: $this->resolveActor($event->actorId, $payment),
            projectId: $payment->project_id !== null ? (int) $payment->project_id : null,
        );
    }
}
