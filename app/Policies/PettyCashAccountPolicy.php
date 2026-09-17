<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PettyCashAccount;
use App\Models\User;

class PettyCashAccountPolicy extends ScopedPolicy
{
    protected string $module = 'petty_cash';

    public function disburse(User $user, PettyCashAccount $account): bool
    {
        return $this->check($user, 'disburse', $account);
    }

    public function replenish(User $user, PettyCashAccount $account): bool
    {
        return $this->check($user, 'replenish', $account);
    }

    public function manage(User $user, PettyCashAccount $account): bool
    {
        return $this->check($user, 'manage', $account);
    }
}
