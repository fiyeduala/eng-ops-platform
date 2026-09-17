<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\ExpenseSubmitted;
use App\Listeners\Concerns\ResolvesActor;

/**
 * An expense claim is waiting for a decision (key `expense.submitted`).
 *
 * Drawn widely — the claimant's head of unit and supervisor, finance, followers — then narrowed to
 * whoever actually holds `approve` over this claim. No amount in the body: the figure sits behind
 * the claim's own confidentiality (§68).
 */
class NotifyExpenseSubmitted
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(ExpenseSubmitted $event): void
    {
        $expense = $event->expense;

        $audience = Audience::followers($expense)->orRole('finance-manager');

        if ($expense->org_unit_id !== null) {
            $audience = $audience->orHeadOf((int) $expense->org_unit_id);
        }

        if ($expense->claimant_id !== null) {
            $audience = $audience->orSupervisorOf((int) $expense->claimant_id);
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'expense.submitted',
                title: 'Expense awaiting approval: '.$expense->number,
                body: $expense->number.' — '.$expense->title.' — has been submitted for approval.',
                actionUrl: route('expenses.show', $expense, absolute: false),
            ),
            audience: $audience,
            subject: $expense,
            requiredAbility: 'approve',
            causer: $this->resolveActor($event->actorId, $expense),
            projectId: $expense->project_id !== null ? (int) $expense->project_id : null,
        );
    }
}
