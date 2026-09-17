<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssetMaintenance;
use App\Models\User;

/**
 * Planning a service and signing one off are two different authorities.
 *
 * `complete` IS SEPARATE FROM `edit` BECAUSE OF WHAT COMPLETION MEANS. Closing a maintenance record
 * is the statement "this machine is safe to use again" — it is the thing a statutory inspection
 * certificate, an insurance claim and an accident investigation all read afterwards. The person who
 * schedules next quarter's services from a desk should be able to plan them; whether the work was
 * actually done is the fitter's or the supervisor's word, not the planner's.
 *
 * That is why `maintenance-planner` in RoleTemplates holds `asset_maintenance.create` and `.edit`
 * but deliberately NOT `.complete`, and why the status graph gates the `in_progress → completed`
 * transition on that exact permission. The graph and this class have to agree: a transition gated
 * on a permission whose policy method does not exist is a transition nobody can make, and an
 * ability granted here with no gate on the graph is a button that changes nothing.
 *
 * `manage` IS THE REOPEN RIGHT. `completed → in_progress` requires it and requires a comment,
 * because a closed job that quietly reopens is how a machine gets signed off twice and inspected
 * once.
 */
class AssetMaintenancePolicy extends ScopedPolicy
{
    protected string $module = 'asset_maintenance';

    /** Sign the work off: the statement that the machine may go back to work. */
    public function complete(User $user, ?AssetMaintenance $maintenance = null): bool
    {
        return $this->check($user, 'complete', $maintenance);
    }

    /** Reopen a closed job, cancel one, override the graph. */
    public function manage(User $user, ?AssetMaintenance $maintenance = null): bool
    {
        return $this->check($user, 'manage', $maintenance);
    }
}
