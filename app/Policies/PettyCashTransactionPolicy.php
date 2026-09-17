<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A voucher is read through its tin. There is no edit and no delete — only a reversal, which is
 * `manage` over the account.
 */
class PettyCashTransactionPolicy extends ScopedPolicy
{
    protected string $module = 'petty_cash';

    public function view(User $user, Model $record): bool
    {
        return $record instanceof PettyCashTransaction
            && $record->account !== null
            && $this->check($user, 'view', $record->account);
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function reverse(User $user, PettyCashTransaction $transaction): bool
    {
        return $transaction->account !== null && $this->check($user, 'manage', $transaction->account);
    }
}
