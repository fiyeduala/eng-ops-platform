<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Submittal;
use App\Models\SubmittalItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One line in a submittal package.
 *
 * Delegates to the parent, exactly as TransmittalItemPolicy does, and for the same reason: without
 * it "a reviewed submittal cannot be edited" would be true of the covering record and false of the
 * list of items underneath — the half that says what was actually reviewed.
 *
 * The one exception is `code`, the per-item verdict. Clients accept nine items and reject the tenth,
 * so an item must be codeable while the parent is being reviewed, which is precisely the moment the
 * parent's own `update` is about to close. It therefore asks for the parent's REVIEW ability rather
 * than its update ability.
 */
class SubmittalItemPolicy extends ScopedPolicy
{
    protected string $module = 'submittal';

    public function view(User $user, Model $record): bool
    {
        return $this->throughSubmittal($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughSubmittal($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughSubmittal($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a line to a package is editing the package. */
    public function createFor(User $user, Submittal $submittal): bool
    {
        return Gate::forUser($user)->allows('update', $submittal);
    }

    /** Record this line's own review code. See the class docblock for why it is not `update`. */
    public function code(User $user, SubmittalItem $item): bool
    {
        $submittal = $item->relationLoaded('submittal') ? $item->submittal : $item->submittal()->first();

        return $submittal !== null && Gate::forUser($user)->allows('review', $submittal);
    }

    private function throughSubmittal(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof SubmittalItem) {
            return null;
        }

        $submittal = $record->relationLoaded('submittal')
            ? $record->submittal
            : $record->submittal()->first();

        return $submittal === null ? false : Gate::forUser($user)->allows($ability, $submittal);
    }
}
