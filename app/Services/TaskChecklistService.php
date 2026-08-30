<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The checklist on a task.
 *
 * Small, but it owns two things a controller must not be trusted with: the tenant and the task.
 * Both are taken from the parent record, never from the request, so a crafted POST cannot file a
 * checklist line against another company's task even if a validator is one day relaxed (§84).
 *
 * Ticking a line records who ticked it and when. On an inspection checklist that is the whole
 * point of the feature — "the box is ticked" is worth nothing without "by whom".
 *
 * This class does NOT authorize; TaskChecklistItemPolicy does, by asking TaskPolicy about the
 * parent.
 */
class TaskChecklistService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function add(Task $task, array $attributes, User $actor): TaskChecklistItem
    {
        return DB::transaction(function () use ($task, $attributes, $actor) {
            /** @var TaskChecklistItem $item */
            $item = $task->checklistItems()->create([
                'organization_id' => $task->organization_id,
                'title' => $attributes['title'],
                'sort' => $attributes['sort'] ?? $this->nextSort($task),
                'created_by' => $actor->id,
                'is_done' => false,
            ]);

            $this->activity->record(
                $task,
                'checklist_added',
                sprintf('%s added a checklist item to %s', $actor->name, $task->displayName()),
                ['title' => $item->title],
                $actor,
            );

            return $item;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(TaskChecklistItem $item, array $attributes, User $actor): TaskChecklistItem
    {
        // Re-pointing a line at a different task or tenant is not an edit.
        unset($attributes['task_id'], $attributes['organization_id'], $attributes['done_by'], $attributes['done_at']);

        $item->fill($attributes);
        $changed = array_keys($item->getDirty());

        if ($changed === []) {
            return $item;
        }

        $item->save();

        return $item;
    }

    /**
     * Tick or untick a line.
     *
     * `done_by` and `done_at` are stamped by the service and cleared on unticking, so the record
     * never says "completed by Sarah" about a box that is currently empty.
     */
    public function toggle(TaskChecklistItem $item, bool $done, User $actor): TaskChecklistItem
    {
        if ($item->is_done === $done) {
            return $item;
        }

        $item->loadMissing('task');

        return DB::transaction(function () use ($item, $done, $actor) {
            $item->is_done = $done;
            $item->done_by = $done ? $actor->id : null;
            $item->done_at = $done ? now() : null;
            $item->save();

            if ($item->task !== null) {
                $this->activity->record(
                    $item->task,
                    $done ? 'checklist_completed' : 'checklist_reopened',
                    sprintf(
                        '%s %s “%s” on %s',
                        $actor->name,
                        $done ? 'ticked' : 'unticked',
                        $item->title,
                        $item->task->displayName(),
                    ),
                    [],
                    $actor,
                );
            }

            return $item;
        });
    }

    public function remove(TaskChecklistItem $item, User $actor): void
    {
        $item->loadMissing('task');

        $task = $item->task;
        $title = $item->title;

        DB::transaction(function () use ($item, $task, $title, $actor) {
            $item->delete();

            if ($task !== null) {
                $this->activity->record(
                    $task,
                    'checklist_removed',
                    sprintf('%s removed a checklist item from %s', $actor->name, $task->displayName()),
                    ['title' => $title],
                    $actor,
                );
            }
        });
    }

    private function nextSort(Task $task): int
    {
        return (int) $task->checklistItems()->max('sort') + 10;
    }
}
