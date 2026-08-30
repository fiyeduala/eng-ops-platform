<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Checklist lines have no permissions of their own — they borrow the task's.
 *
 * This is a deliberate decision, not a shortcut. PermissionCatalogue declares no
 * `task_checklist_item.*` keys, and inventing them here would produce permissions no administrator
 * could ever grant through the role editor, i.e. a feature that is dead the moment it ships. The
 * honest model is the one the UI already implies: a checklist is part of a task, so whoever may
 * edit the task may edit its checklist, and whoever may see the task may see it.
 *
 * Every ability therefore resolves against the PARENT through TaskPolicy, which runs the full five
 * gates — including confidentiality — on the record that actually carries them. Nothing here
 * re-implements reach, so nothing here can drift out of step with it.
 *
 * A line whose parent has vanished authorizes nothing. That should be unreachable (the foreign key
 * cascades) but "should be unreachable" is not an access decision.
 */
class TaskChecklistItemPolicy extends ScopedPolicy
{
    protected string $module = 'task';

    public function viewAny(User $user): bool
    {
        return $this->tasks()->viewAny($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'view');
    }

    /**
     * Adding a line is editing the task. The controller holds the task and calls TaskPolicy
     * directly; this exists for the class-level `@can('create', TaskChecklistItem::class)` case,
     * where there is no parent to ask about and the only honest answer is the permission check.
     */
    public function create(User $user): bool
    {
        return $this->check($user, 'update');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'update');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->throughParent($user, $record, 'update');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    private function throughParent(User $user, Model $record, string $ability): bool
    {
        if (! $record instanceof TaskChecklistItem) {
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
