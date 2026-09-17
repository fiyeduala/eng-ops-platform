<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ExpensePolicy extends ScopedPolicy
{
    protected string $module = 'expense';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Expense && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Expense && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function submit(User $user, Expense $expense): bool
    {
        return $expense->statusKey() === 'draft' && $this->check($user, 'submit', $expense);
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $this->check($user, 'approve', $expense);
    }

    public function reject(User $user, Expense $expense): bool
    {
        return $this->check($user, 'reject', $expense);
    }

    public function manage(User $user, Expense $expense): bool
    {
        return $this->check($user, 'manage', $expense);
    }
}
