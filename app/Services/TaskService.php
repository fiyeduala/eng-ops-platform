<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Events\TaskAssigned;
use App\Models\Follower;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens when a task is raised, assigned, moved or closed (§16, §88).
 *
 * "Create a task" has to mean the same thing from the create form, from a workflow step, from an
 * HSE incident spawning a corrective action in Phase 4, and from the seeder (§90). Five things
 * happen every time and no caller is trusted to remember them:
 *
 *   1. The number comes from the numbering engine, never from a person.
 *   2. The opening status comes from the configured state machine, so a company that renamed
 *      "To do" to "Raised" gets its own word without anyone editing PHP.
 *   3. Assignment stamps who assigned it and when — the audit question after the fact is always
 *      "who put this on them", and a bare assignee_id cannot answer it.
 *   4. The assignee and the raiser are subscribed to the task, with `reason` recording that the
 *      system did it, so a later "unfollow" is a person's choice and not overwritten next time.
 *   5. The project timeline gets a sentence.
 *
 * What this class does NOT do is authorize anything — callers have been through TaskPolicy. It
 * also does not decide WHO may be assigned; that is TaskPolicy::assign(), asked in the controller
 * with the candidate resolved from the request body (§84).
 *
 * The data-integrity rules that live here rather than in a policy are the ones about the work
 * itself: a task cannot close while something it waits on is still open, and a task cannot be its
 * own ancestor. Those are true regardless of who is asking.
 */
class TaskService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly ActorProfile $profile,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes  already validated, and with `assignee_id` already
     *                                           cleared through TaskPolicy::assign()
     */
    public function create(array $attributes, User $actor): Task
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $task = new Task($attributes);

            // Tenant, number, status and authorship belong to the service. A caller able to pass
            // its own organization_id would need only one careless validator to write into another
            // company (§74, §75).
            $task->organization_id = $actor->organization_id;
            $task->created_by = $actor->id;
            $task->number = $this->numbers->next('task');
            $task->status_id ??= $this->statuses->initialFor('task')?->id;

            if ($task->assignee_id !== null) {
                $task->assigned_by = $actor->id;
                $task->assigned_at = now();
            }

            $this->guardParent($task);
            $task->save();

            $this->subscribe($task, (int) $actor->id, 'creator', $actor);

            if ($task->assignee_id !== null) {
                $this->subscribe($task, (int) $task->assignee_id, 'assignee', $actor);
            }

            $this->activity->record(
                $task,
                'created',
                sprintf('%s raised task %s', $actor->name, $task->displayName()),
                array_filter([
                    'number' => $task->number,
                    'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
                ]),
                $actor,
            );

            // Assigning a task grants the assignee sight of it; the memoized reach is now stale.
            $this->profile->flush();

            if ($task->assignee_id !== null) {
                TaskAssigned::dispatch($task, (int) $task->assignee_id, null, (int) $actor->id);
            }

            return $task->refresh();
        });
    }

    /**
     * Edit the task's own fields.
     *
     * `assignee_id` is unset here on purpose. Assignment is a grant and has its own method, its own
     * policy question and its own event; letting it ride in on a general update would mean the
     * candidate check could be skipped by anyone who found the edit form.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Task $task, array $attributes, User $actor): Task
    {
        unset(
            $attributes['number'],
            $attributes['organization_id'],
            $attributes['created_by'],
            $attributes['assignee_id'],
            $attributes['assigned_by'],
            $attributes['assigned_at'],
            $attributes['status_id'],
            $attributes['completed_at'],
            $attributes['completed_by'],
        );

        return DB::transaction(function () use ($task, $attributes, $actor) {
            $task->fill($attributes);
            $this->guardParent($task);

            $changed = array_keys($task->getDirty());

            if ($changed === []) {
                return $task;
            }

            $task->save();

            $this->activity->record(
                $task,
                'updated',
                sprintf('%s updated %s', $actor->name, $task->displayName()),
                ['fields' => $changed],
                $actor,
            );

            // project_id and org_unit_id are both reach columns: moving a task between projects
            // changes who can see it.
            $this->profile->flush();

            return $task->refresh();
        });
    }

    /**
     * Put the task on somebody, or take it off them.
     *
     * The caller has already asked TaskPolicy::assign()/reassign() with this exact candidate. What
     * happens here is the bookkeeping that makes the grant auditable and the notification possible.
     */
    public function assign(Task $task, ?User $assignee, User $actor): Task
    {
        $previous = $task->assignee_id !== null ? (int) $task->assignee_id : null;
        $next = $assignee?->id !== null ? (int) $assignee->id : null;

        if ($previous === $next) {
            return $task;
        }

        return DB::transaction(function () use ($task, $assignee, $actor, $previous, $next) {
            $task->assignee_id = $next;
            $task->assigned_by = $next !== null ? $actor->id : null;
            $task->assigned_at = $next !== null ? now() : null;
            $task->save();

            if ($next !== null) {
                $this->subscribe($task, $next, 'assignee', $actor);
            }

            // The previous assignee's subscription is deliberately left in place. They worked on
            // this; being told how it ends is not a leak, and the notification layer re-checks
            // their reach at send time anyway (§68).

            $this->activity->record(
                $task,
                $next === null ? 'unassigned' : ($previous === null ? 'assigned' : 'reassigned'),
                $next === null
                    ? sprintf('%s unassigned %s', $actor->name, $task->displayName())
                    : sprintf(
                        '%s assigned %s to %s',
                        $actor->name,
                        $task->displayName(),
                        $assignee?->name ?? 'someone',
                    ),
                array_filter([
                    'from_user_id' => $previous,
                    'to_user_id' => $next,
                ], fn ($v) => $v !== null),
                $actor,
            );

            $this->profile->flush();

            if ($next !== null) {
                TaskAssigned::dispatch($task, $next, $previous, (int) $actor->id);
            }

            return $task->refresh();
        });
    }

    /**
     * Move the task through the configured whitelist.
     *
     * Thin on purpose — the rules live in StatusMachine so a project, a task and an NCR all move
     * the same way (§63). What is added is the two facts that follow from a move and would
     * otherwise be somebody's job to remember:
     *
     *   - A task may not enter a terminal status while something it waits on is still open. This
     *     is the point of recording dependencies at all; enforcing it only in the UI would make
     *     the graph decorative.
     *   - Closing stamps completion. Reopening clears it, because a task that is open with a
     *     completion date on it is a record that lies.
     *
     * @throws \DomainException when the move is not defined, not permitted, or blocked
     */
    public function transition(Task $task, Status $to, User $actor, ?string $comment = null): Task
    {
        if ($to->is_terminal && $to->key !== 'cancelled') {
            $blocking = $task->blockingDependencies();

            if ($blocking->isNotEmpty()) {
                throw new \DomainException(
                    'This task is waiting on '.$blocking->map(
                        fn (Task $t) => $t->number
                    )->implode(', ').', which is not finished yet.'
                );
            }
        }

        $this->statuses->transition($task, $to, $actor, $comment);

        $task->refresh();

        if ($task->isClosed() && $task->completed_at === null) {
            $task->completed_at = now();
            $task->completed_by = $actor->id;
            $task->progress_percent = $to->key === 'cancelled' ? $task->progress_percent : 100;
            $task->saveQuietly();
        }

        if (! $task->isClosed() && $task->completed_at !== null) {
            $task->completed_at = null;
            $task->completed_by = null;
            $task->saveQuietly();
        }

        return $task->refresh();
    }

    /**
     * Subscribe a user to a task, idempotently.
     *
     * firstOrCreate rather than create: someone who already follows a task and is then assigned it
     * keeps the `manual` reason they chose, and the unique key is never hit. Following grants no
     * access whatsoever — see the Follower model — so this is safe to do on the system's own
     * initiative in a way that a project membership row would not be.
     */
    public function subscribe(Task $task, int $userId, string $reason, ?User $actor = null): Follower
    {
        /** @var Follower $follower */
        $follower = Follower::query()->firstOrCreate(
            [
                'followable_type' => $task->getMorphClass(),
                'followable_id' => $task->getKey(),
                'user_id' => $userId,
            ],
            [
                'organization_id' => $task->organization_id,
                'reason' => $reason,
                'created_by' => $actor?->id,
            ],
        );

        return $follower;
    }

    public function unsubscribe(Task $task, int $userId): void
    {
        Follower::query()
            ->where('followable_type', $task->getMorphClass())
            ->where('followable_id', $task->getKey())
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * A task cannot be its own ancestor.
     *
     * Cheap to check and expensive to miss: a parent cycle makes every "show me this task's
     * subtasks" query non-terminating, and the breadcrumb on the task page recurses until PHP
     * gives up. Walked rather than compared, because A→B→A is as much a cycle as A→A.
     *
     * @throws \DomainException
     */
    private function guardParent(Task $task): void
    {
        $parentId = $task->parent_id !== null ? (int) $task->parent_id : null;

        if ($parentId === null) {
            return;
        }

        if ($task->exists && $parentId === (int) $task->getKey()) {
            throw new \DomainException('A task cannot be its own parent.');
        }

        $selfId = $task->exists ? (int) $task->getKey() : null;
        $seen = [];

        while ($parentId !== null) {
            if ($selfId !== null && $parentId === $selfId) {
                throw new \DomainException('That would make the task its own subtask.');
            }

            if (in_array($parentId, $seen, true)) {
                // Pre-existing cycle in the data. Refusing here is the only safe answer; repairing
                // it silently would hide a defect that produced it.
                throw new \DomainException('The parent task chain is circular.');
            }

            $seen[] = $parentId;
            $parentId = Task::query()->whereKey($parentId)->value('parent_id');
            $parentId = $parentId !== null ? (int) $parentId : null;
        }
    }
}
