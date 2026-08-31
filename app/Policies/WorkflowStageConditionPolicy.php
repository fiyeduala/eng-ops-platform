<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A condition is a clause inside a stage, one level further down than WorkflowStagePolicy.
 *
 * "Only run this stage when the value exceeds ten million" is not a record anybody owns; it is a
 * line of the route's configuration. So the question delegates up: stage, then workflow, then the
 * five gates. Three models, one answer.
 */
class WorkflowStageConditionPolicy extends ScopedPolicy
{
    protected string $module = 'workflow';

    public function view(User $user, Model $record): bool
    {
        return $this->throughStage($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughStage($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughStage($user, 'update', $record) ?? parent::delete($user, $record);
    }

    public function createFor(User $user, WorkflowStage $stage): bool
    {
        return Gate::forUser($user)->allows('update', $stage);
    }

    private function throughStage(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof WorkflowStageCondition) {
            return null;
        }

        $stage = $record->relationLoaded('stage') ? $record->stage : $record->stage()->first();

        return $stage === null ? false : Gate::forUser($user)->allows($ability, $stage);
    }
}
