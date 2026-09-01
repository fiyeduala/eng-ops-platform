<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QualityInspection;
use App\Models\QualityInspectionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One checkpoint on one quality inspection. Reach is the parent's, and so is the freeze.
 *
 * The consequence is the point: once the inspection is recorded, the parent refuses `update`, so no
 * measured value and no result can be altered. The measurements ARE the inspection record.
 */
class QualityInspectionItemPolicy extends ScopedPolicy
{
    protected string $module = 'quality_inspection';

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

    public function createFor(User $user, QualityInspection $inspection): bool
    {
        return Gate::forUser($user)->allows('update', $inspection);
    }

    private function throughInspection(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof QualityInspectionItem) {
            return null;
        }

        $inspection = $record->relationLoaded('inspection')
            ? $record->inspection
            : $record->inspection()->first();

        return $inspection === null ? false : Gate::forUser($user)->allows($ability, $inspection);
    }
}
