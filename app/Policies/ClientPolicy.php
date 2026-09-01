<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * Who may see and edit a client.
 *
 * SHORT, LIKE THE SERVICE, AND FOR THE SAME REASON. A client record cannot lose the company money
 * by being edited: there are no bank details to redirect, no prequalification to expire and no
 * blacklisting to lift. The five gates in ScopedPolicy already answer everything that matters here.
 *
 * `assignOwner()` IS THE ONE ADDITION, and it earns its place because handing an account to
 * somebody CHANGES WHO CAN SEE IT — `relationship_owner_id` is what the `assigned` access scope
 * reads. An ability that grants reach must not be reachable through ordinary `edit`, or a user with
 * `own` scope could widen their own by naming themselves on every client in the register.
 *
 * @see VendorPolicy for the asymmetry, argued from the other side.
 */
class ClientPolicy extends ScopedPolicy
{
    protected string $module = 'client';

    /** Hand the account to somebody else. `manage`, because it grants visibility. */
    public function assignOwner(User $user, Client $client): bool
    {
        return $this->check($user, 'manage', $client);
    }
}
