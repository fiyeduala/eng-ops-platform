<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What is on the shelf: readable, countable, and never edited by hand.
 *
 * A BALANCE IS A CACHE, so editing one directly would not change the stock — it would make the row
 * disagree with the ledger that defines it, and the next movement would overwrite the change
 * without telling anybody. `update()` is therefore false for everybody, in the same spirit as
 * StockTransactionPolicy: the way to change a balance is to post a movement, and the way to change
 * it without a movement having happened is a stocktake adjustment, which posts a movement too.
 *
 * `count()` IS THE STOCKTAKE ENTRY POINT and is deliberately separate from `adjust` on the ledger.
 * Recording that you counted thirty-eight is an observation anybody trusted with the store may
 * make; posting the two that went missing is a variance somebody senior signs off, and
 * `inventory.separate_counter_and_adjuster` lets a company insist they are different people. That
 * split is why the count lives here and the consequence lives there.
 */
class StockBalancePolicy extends ScopedPolicy
{
    protected string $module = 'stock';

    /** @param  Model  $record  Unused: a cached figure is not editable by hand. */
    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    /** Record a physical count against this row. The variance is a separate act. */
    public function count(User $user, StockBalance $balance): bool
    {
        return $this->check($user, 'create', $balance);
    }

    /** See what the stock is worth, as opposed to how much of it there is (§68). */
    public function viewValue(User $user, ?StockBalance $balance = null): bool
    {
        return $this->check($user, 'view_value', $balance);
    }
}
