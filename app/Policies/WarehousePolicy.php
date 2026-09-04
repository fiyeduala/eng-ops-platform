<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

/**
 * Who may set up a store, and — separately — who may freeze one.
 *
 * `suspend()` IS ITS OWN ABILITY AND NOT PART OF `manage`, which is the only interesting decision
 * in this class. Suspending a warehouse stops every other person in the company from receiving or
 * issuing anything through it, so it is exactly the authority a stock supervisor needs on the
 * morning of a count — and exactly the authority that should not also let them rename the store,
 * move it to another project, or change who keeps it. Folding the two together would mean a company
 * either gives the supervisor everything or makes them ring the administrator to start a stocktake.
 *
 * DELETING A WAREHOUSE IS DELIBERATELY LEFT TO `delete`, unnarrowed, because the interesting
 * refusal is not a permission question: WarehouseService refuses to delete a store that has ever
 * held stock, whoever is asking. A policy that also said no would give the wrong reason.
 */
class WarehousePolicy extends ScopedPolicy
{
    protected string $module = 'warehouse';

    /** Freeze the store for a stocktake, or let it go again. */
    public function suspend(User $user, Warehouse $warehouse): bool
    {
        return $this->check($user, 'suspend', $warehouse);
    }

    public function manage(User $user, Warehouse $warehouse): bool
    {
        return $this->check($user, 'manage', $warehouse);
    }
}
