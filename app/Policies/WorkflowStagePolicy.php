<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A stage is part of its route's definition, not a record of its own.
 *
 * Every ability delegates to WorkflowPolicy against the parent workflow, so there is exactly one
 * answer to "may this person change how approvals are routed" and no second place for it to drift.
 * A stage reached directly — an id posted in a form, a URL typed by hand (§84) — lands on the same
 * check as opening the builder.
 */
class WorkflowStagePolicy extends ScopedPolicy
{
    protected string $module = 'workflow';

    public function view(User $user, Model $record): bool
    {
        return $this->throughWorkflow($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughWorkflow($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughWorkflow($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a stage is editing the route, so it asks about the route. */
    public function createFor(User $user, Workflow $workflow): bool
    {
        return Gate::forUser($user)->allows('update', $workflow);
    }

    /**
     * Null means "not a stage" and hands the question back to the parent class; false means the
     * parent workflow is missing, which authorizes nothing.
     */
    private function throughWorkflow(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof WorkflowStage) {
            return null;
        }

        $workflow = $record->relationLoaded('workflow') ? $record->workflow : $record->workflow()->first();

        return $workflow === null ? false : Gate::forUser($user)->allows($ability, $workflow);
    }
}
