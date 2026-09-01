<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;

/**
 * The counterparty lists behind every party picker.
 *
 * EACH LIST CARRIES ITS OWN REGISTER'S PERMISSION. A picker is not a route around the client or
 * vendor register's access rules: somebody who cannot see the client list must not be able to read
 * it out of an RFI form. §61 says a search obeys exactly the same authorization as a page, and a
 * select box is a search with its results pre-fetched.
 *
 * BOTH LISTS COME BACK EMPTY for a user holding neither permission, and the picker then renders as
 * the free-text field it has always been. That is the right degradation rather than an error: a
 * site engineer raising a query to a regulator needs to type a name, not to be told about a
 * register they cannot open.
 *
 * Capped at 500 for the same reason the project list is: a select is not a search box. The cap is
 * a rendering decision; the SCOPE is the security one, and that comes from the compiler.
 */
trait OffersPartyOptions
{
    /**
     * @return array{clients: array<int|string,string>, vendors: array<int|string,string>}
     */
    protected function partyOptions(User $user): array
    {
        return [
            'clients' => $user->can('viewAny', Client::class)
                ? $this->compiler->apply(Client::query(), $user, 'client.view')
                    ->orderBy('name')->limit(500)->pluck('name', 'id')->all()
                : [],
            'vendors' => $user->can('viewAny', Vendor::class)
                ? $this->compiler->apply(Vendor::query(), $user, 'vendor.view')
                    ->orderBy('name')->limit(500)->pluck('name', 'id')->all()
                : [],
        ];
    }
}
