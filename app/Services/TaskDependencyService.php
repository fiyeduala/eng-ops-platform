<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "This task waits for that one."
 *
 * Three integrity rules live here because none of them is an authorization question — they are
 * true no matter who is asking (§100 priority 3):
 *
 *   1. A task cannot depend on itself.
 *   2. A dependency may not close a cycle. A→B→C→A makes "can this start?" unanswerable, and any
 *      scheduling or critical-path work built on the graph later would loop.
 *   3. Both tasks must be in the same tenant. The foreign keys do not know that, because they
 *      point at `tasks` and every tenant's tasks live there.
 *
 * Cycle detection walks forward from the proposed blocker: if this task is already reachable from
 * it, the new edge would close a loop. Bounded by the number of edges, and dependency graphs on
 * one project are small — the alternative, a recursive CTE, is not portable to the MySQL 5.7
 * installs a lot of cPanel hosting still runs.
 *
 * Authorization is TaskDependencyPolicy's, with one addition the policy cannot make: the caller
 * must also be able to SEE the blocking task, which the controller checks before calling `add`.
 * Otherwise declaring a dependency would be a way to confirm that a task exists and then have its
 * number and title rendered on a page.
 */
class TaskDependencyService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @throws \DomainException when the edge is impossible, duplicated, or would close a cycle
     */
    public function add(Task $task, Task $dependsOn, User $actor, string $type = 'finish_to_start'): TaskDependency
    {
        if ((int) $task->getKey() === (int) $dependsOn->getKey()) {
            throw new \DomainException('A task cannot depend on itself.');
        }

        if ((int) $task->organization_id !== (int) $dependsOn->organization_id) {
            throw new \DomainException('Both tasks must belong to the same organization.');
        }

        $exists = TaskDependency::query()
            ->where('task_id', $task->getKey())
            ->where('depends_on_task_id', $dependsOn->getKey())
            ->exists();

        if ($exists) {
            throw new \DomainException(sprintf('%s already waits for %s.', $task->number, $dependsOn->number));
        }

        if ($this->reaches($dependsOn, (int) $task->getKey())) {
            throw new \DomainException(sprintf(
                'That would create a loop: %s already waits for %s, directly or indirectly.',
                $dependsOn->number,
                $task->number,
            ));
        }

        return DB::transaction(function () use ($task, $dependsOn, $actor, $type) {
            /** @var TaskDependency $edge */
            $edge = $task->dependencies()->create([
                'organization_id' => $task->organization_id,
                'depends_on_task_id' => $dependsOn->getKey(),
                'type' => $type,
                'created_by' => $actor->id,
            ]);

            $this->activity->record(
                $task,
                'dependency_added',
                sprintf(
                    '%s made %s wait for %s',
                    $actor->name,
                    $task->displayName(),
                    $dependsOn->displayName(),
                ),
                ['depends_on_task_id' => (int) $dependsOn->getKey()],
                $actor,
            );

            return $edge;
        });
    }

    public function remove(TaskDependency $edge, User $actor): void
    {
        $edge->loadMissing(['task', 'dependsOn']);

        $task = $edge->task;
        $blocker = $edge->dependsOn;

        DB::transaction(function () use ($edge, $task, $blocker, $actor) {
            $edge->delete();

            if ($task !== null) {
                $this->activity->record(
                    $task,
                    'dependency_removed',
                    sprintf(
                        '%s removed the dependency of %s on %s',
                        $actor->name,
                        $task->displayName(),
                        $blocker?->displayName() ?? 'another task',
                    ),
                    [],
                    $actor,
                );
            }
        });
    }

    /**
     * Is `$target` reachable by following dependencies forward from `$from`?
     *
     * Breadth-first over `task_id -> depends_on_task_id`, with a visited set so an existing cycle
     * in the data cannot hang the request while we are busy refusing to create a new one.
     */
    private function reaches(Task $from, int $target): bool
    {
        $frontier = [(int) $from->getKey()];
        $seen = [];

        while ($frontier !== []) {
            $next = TaskDependency::query()
                ->whereIn('task_id', $frontier)
                ->pluck('depends_on_task_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $seen = array_merge($seen, $frontier);
            $frontier = [];

            foreach ($next as $id) {
                if ($id === $target) {
                    return true;
                }

                if (! in_array($id, $seen, true)) {
                    $frontier[] = $id;
                }
            }

            $frontier = array_values(array_unique($frontier));
        }

        return false;
    }
}
