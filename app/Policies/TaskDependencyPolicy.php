<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Dependency edges borrow the WAITING task's permissions, for the same reason checklist lines
 * borrow their task's: the catalogue declares no `task_dependency.*` keys, and a permission no
 * administrator can grant is a feature nobody can use.
 *
 * The asymmetry is the interesting part. An edge names two tasks, and the authorization question
 * is different on each side:
 *
 *   - The WAITING task (`task_id`) is the one being constrained, and it is the side the edge is
 *     created from. Reach over it, at `update`, is what this policy checks.
 *   - The BLOCKING task (`depends_on_task_id`) is only checked at creation, and only for `view` —
 *     see TaskDependencyService. Otherwise "my task depends on #412" would be a way to confirm
 *     that #412 exists, and its number and title would then render on a page for someone with no
 *     right to either. Declaring a dependency must not be a directory of the tasks you cannot see.
 */
class TaskDependencyPolicy extends ScopedPolicy
{
    protected string $module = 'task';

    public function viewAny(User $user): bool
    {
        return $this->tasks()->viewAny($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->throughWaitingTask($user, $record, 'view');
    }

    public function create(User $user): bool
    {
        return $this->check($user, 'update');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughWaitingTask($user, $record, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughWaitingTask($user, $record, 'update');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->throughWaitingTask($user, $record, 'update');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    private function throughWaitingTask(User $user, Model $record, string $ability): bool
    {
        if (! $record instanceof TaskDependency) {
            return false;
        }

        $task = $record->relationLoaded('task') ? $record->task : $record->task()->first();

        return $task !== null && $this->tasks()->check($user, $ability, $task);
    }

    private function tasks(): TaskPolicy
    {
        return app(TaskPolicy::class);
    }
}
