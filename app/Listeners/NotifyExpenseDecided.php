<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\ExpenseDecided;
use App\Listeners\Concerns\ResolvesActor;

/** The claimant hears the answer — approved, returned or rejected (key `expense.decided`). */
class NotifyExpenseDecided
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(ExpenseDecided $event): void
    {
        $expense = $event->expense;
        $key = $expense->status?->key;

        $verb = match ($key) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'draft' => 'returned to you for amendment',
            default => 'answered',
        };

        $reason = in_array($key, ['rejected', 'draft'], true) && filled($expense->decision_reason)
            ? ' Reason: '.$expense->decision_reason
            : '';

        $audience = Audience::followers($expense);

        foreach ([$expense->claimant_id, $expense->created_by] as $userId) {
            if ($userId !== null) {
                $audience = $audience->orUser((int) $userId);
            }
        }

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'expense.decided',
                title: 'Expense '.$verb.': '.$expense->number,
                body: $expense->number.' — '.$expense->title.' — has been '.$verb.'.'.$reason,
                actionUrl: route('expenses.show', $expense, absolute: false),
            ),
            audience: $audience,
            subject: $expense,
            causer: $this->resolveActor($event->actorId, $expense),
            projectId: $expense->project_id !== null ? (int) $expense->project_id : null,
        );
    }
}
