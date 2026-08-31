<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A line on a transmittal: one revision, so many copies, sent this way.
 *
 * Delegates entirely to the parent transmittal, which is what makes the issued/draft rule hold on
 * the lines as well as the header. Without that, "cannot edit an issued transmittal" would be true
 * of the covering note and false of the list of documents underneath it — the half that matters.
 */
class TransmittalItemPolicy extends ScopedPolicy
{
    protected string $module = 'transmittal';

    public function view(User $user, Model $record): bool
    {
        return $this->throughTransmittal($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughTransmittal($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughTransmittal($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a document to a transmittal is editing the transmittal. */
    public function createFor(User $user, Transmittal $transmittal): bool
    {
        return Gate::forUser($user)->allows('update', $transmittal);
    }

    private function throughTransmittal(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof TransmittalItem) {
            return null;
        }

        $transmittal = $record->relationLoaded('transmittal')
            ? $record->transmittal
            : $record->transmittal()->first();

        return $transmittal === null ? false : Gate::forUser($user)->allows($ability, $transmittal);
    }
}
