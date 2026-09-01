<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A named person at a client, reachable exactly as far as the client is.
 *
 * The same delegation as VendorContactPolicy, and worth having twice rather than collapsing into
 * one class with a nullable parent: the two ask their parents different abilities as the registers
 * diverge, and a shared "party contact" policy would have to branch on the class anyway.
 */
class ClientContactPolicy extends ScopedPolicy
{
    protected string $module = 'client';

    public function view(User $user, Model $record): bool
    {
        return $this->throughClient($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughClient($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughClient($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a contact to a client is editing the client. */
    public function createFor(User $user, Client $client): bool
    {
        return Gate::forUser($user)->allows('update', $client);
    }

    private function throughClient(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof ClientContact) {
            return null;
        }

        $client = $record->relationLoaded('client') ? $record->client : $record->client()->first();

        return $client === null ? false : Gate::forUser($user)->allows($ability, $client);
    }
}
