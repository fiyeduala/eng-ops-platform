<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Income;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IncomePolicy extends ScopedPolicy
{
    protected string $module = 'income';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Income && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Income && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function issue(User $user, Income $income): bool
    {
        return $this->check($user, 'issue', $income);
    }

    public function manage(User $user, Income $income): bool
    {
        return $this->check($user, 'manage', $income);
    }
}
