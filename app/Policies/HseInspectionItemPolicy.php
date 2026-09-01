<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HseInspection;
use App\Models\HseInspectionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One checkpoint on one inspection. Reach is the parent's, and so is the freeze.
 *
 * NOTE THE CONSEQUENCE, because it is the point rather than a side effect: once the inspection is
 * performed, the parent refuses `update`, so no item's result can be altered. The results ARE the
 * inspection, and a register in which individual lines stay editable after the walk has frozen
 * nothing at all.
 */
class HseInspectionItemPolicy extends ScopedPolicy
{
    protected string $module = 'hse_inspection';

    public function view(User $user, Model $record): bool
    {
        return $this->throughInspection($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughInspection($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughInspection($user, 'update', $record) ?? parent::delete($user, $record);
    }

    public function createFor(User $user, HseInspection $inspection): bool
    {
        return Gate::forUser($user)->allows('update', $inspection);
    }

    private function throughInspection(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof HseInspectionItem) {
            return null;
        }

        $inspection = $record->relationLoaded('inspection')
            ? $record->inspection
            : $record->inspection()->first();

        return $inspection === null ? false : Gate::forUser($user)->allows($ability, $inspection);
    }
}
