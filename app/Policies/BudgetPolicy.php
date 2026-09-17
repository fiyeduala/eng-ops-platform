<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BudgetPolicy extends ScopedPolicy
{
    protected string $module = 'budget';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Budget && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Budget && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function approve(User $user, Budget $budget): bool
    {
        return $this->check($user, 'approve', $budget);
    }

    public function manage(User $user, Budget $budget): bool
    {
        return $this->check($user, 'manage', $budget);
    }
}
