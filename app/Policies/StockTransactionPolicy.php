<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The ledger: readable by many, writable by few, editable by nobody.
 *
 * `update()` AND `delete()` RETURN FALSE UNCONDITIONALLY. Not "false unless you have manage" —
 * false. The model already throws on any update or delete from any caller (§59), so this class is
 * the second of two locks rather than the only one, and it exists because a policy that inherited
 * the ordinary CRUD answers would tell an administrator with `stock.manage` that they may edit a
 * movement, and they would find out otherwise only when the model threw at them.
 *
 * THE CATALOGUE AGREES: `stock` has no `edit` or `delete` ability to grant. All three statements —
 * the missing abilities, this class, and the model's boot hooks — say the same thing, which is the
 * point. A correction is a reversal, and a reversal is a new row that names the old one.
 *
 * `adjust` AND `reverse` ARE SEPARATE ABILITIES from `create` and from each other. Posting a
 * stocktake variance creates stock out of nothing and is the single most abusable act in inventory;
 * reversing somebody else's movement is a supervisor's correction. Neither belongs to the person
 * who receives deliveries all day, and neither implies the other.
 *
 * `viewValue()` IS WHY THE LEDGER IS NOT SIMPLY OPEN. What stock cost is commercially sensitive —
 * it is the margin on a job, backwards — and plenty of people who must see that forty metres are on
 * the shelf have no business seeing what the company paid for them (§68). The quantity and the
 * value are two different questions and this is the one that needs its own answer.
 */
class StockTransactionPolicy extends ScopedPolicy
{
    protected string $module = 'stock';

    /**
     * A movement is written once and never rewritten.
     *
     * @param  Model  $record  Unused: there is no ledger row anybody may edit.
     */
    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    /** Post a stocktake variance: stock that appears or vanishes without a movement. */
    public function adjust(User $user, ?StockTransaction $transaction = null): bool
    {
        return $this->check($user, 'adjust', $transaction);
    }

    /** Move stock between two stores. Two rows, one act, one permission. */
    public function transfer(User $user, ?StockTransaction $transaction = null): bool
    {
        return $this->check($user, 'transfer', $transaction);
    }

    /** Undo a movement by posting its opposite. */
    public function reverse(User $user, StockTransaction $transaction): bool
    {
        return $this->check($user, 'reverse', $transaction);
    }

    /** See what it cost, as opposed to how much of it there is. */
    public function viewValue(User $user, ?StockTransaction $transaction = null): bool
    {
        return $this->check($user, 'view_value', $transaction);
    }

    public function manage(User $user, ?StockTransaction $transaction = null): bool
    {
        return $this->check($user, 'manage', $transaction);
    }
}
