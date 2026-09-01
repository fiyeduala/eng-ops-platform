<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A named person at a vendor, reachable exactly as far as the vendor is.
 *
 * Delegating rather than re-deriving is the point: a contact carries a direct line and a personal
 * email address, and a policy that answered on its own would eventually answer differently from the
 * vendor's — at which point a user who cannot open the supplier can still read who to call there.
 *
 * Adding, editing and retiring a contact are all "editing the vendor", so they ask the parent's
 * `update`. There is no separate `vendor_contact` permission module for the same reason a task's
 * checklist has none: it would be a checkbox no administrator could explain.
 */
class VendorContactPolicy extends ScopedPolicy
{
    protected string $module = 'vendor';

    public function view(User $user, Model $record): bool
    {
        return $this->throughVendor($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughVendor($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughVendor($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a contact to a vendor is editing the vendor. */
    public function createFor(User $user, Vendor $vendor): bool
    {
        return Gate::forUser($user)->allows('update', $vendor);
    }

    private function throughVendor(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof VendorContact) {
            return null;
        }

        $vendor = $record->relationLoaded('vendor') ? $record->vendor : $record->vendor()->first();

        return $vendor === null ? false : Gate::forUser($user)->allows($ability, $vendor);
    }
}
