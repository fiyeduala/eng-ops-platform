<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockItem;
use App\Models\User;

/**
 * Who may add a line to the catalogue.
 *
 * NOTHING IS NARROWED HERE, and that is worth stating rather than leaving as an empty class. A
 * catalogue entry has no lifecycle worth defending: it is reference data, it is read by nearly
 * everybody, and the two acts that matter — discontinuing a line and reinstating it — are status
 * transitions the workflow engine already guards with `stock_item.manage`.
 *
 * THE ONE THING THAT WOULD BE WRONG HERE is a rule tying catalogue rights to stock rights. A buyer
 * who may not touch a single movement absolutely must be able to add the flange they are about to
 * order, and a storekeeper who moves stock all day has no business renaming the things they move.
 */
class StockItemPolicy extends ScopedPolicy
{
    protected string $module = 'stock_item';

    public function manage(User $user, StockItem $item): bool
    {
        return $this->check($user, 'manage', $item);
    }
}
