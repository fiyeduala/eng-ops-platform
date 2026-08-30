<?php

declare(strict_types=1);

namespace App\Policies;

use App\Engines\Access\ActorProfile;
use App\Models\Task;
use App\Models\User;
use App\Support\Enums\AccessScope;

/**
 * Tasks (§16, §88).
 *
 * The five gates are inherited, and with them the org-axis ∪ project-axis union that makes a task
 * visible to its assignee, to their management chain, and to the project team — without this class
 * writing a line of SQL.
 *
 * What is added is the part a CRUD policy loses: assigning a task is a GRANT, not an edit.
 * `task.view:assigned` is a real seeded permission, so writing `assignee_id` hands that account
 * sight of this record. So `assign()` asks the same two questions ProjectMemberPolicy asks for
 * team membership — may the actor touch THIS task, and may they commit THAT person — because
 * asking only the first lets a department administrator pinned to one department assign a task to
 * the Managing Director and then read every comment and attachment on it through the reply.
 *
 * `complete` and `verify` are separate from `edit` for the reason §16 separates them: the person
 * doing the work says it is finished, and somebody else says it is acceptable. A single `edit`
 * permission would let the doer close their own inspection.
 */
class TaskPolicy extends ScopedPolicy
{
    protected string $module = 'task';

    /**
     * Administer the task itself: re-parent it, move it between projects, reopen it, cancel it.
     *
     * Separate from `update`, which is the assignee correcting a description or logging hours.
     */
    public function manage(User $user, Task $task): bool
    {
        return $this->check($user, 'manage', $task);
    }

    /** Mark the work done. Held at `assigned` scope by every field role, which is the point. */
    public function complete(User $user, Task $task): bool
    {
        return $this->check($user, 'complete', $task);
    }

    /** Accept somebody else's work. Deliberately a different permission from `complete`. */
    public function verify(User $user, Task $task): bool
    {
        return $this->check($user, 'verify', $task);
    }

    /**
     * Question 1 and question 2, together. Controllers must call THIS, never plain `create`:
     * `create` cannot see the task or the candidate, so it can only answer "does this kind of
     * person assign work at all".
     *
     * A null candidate means "unassign", which revokes rather than grants and needs only
     * question 1 — the mirror of ProjectMemberPolicy::remove().
     */
    public function assign(User $user, Task $task, ?User $candidate = null): bool
    {
        if (! $this->check($user, 'assign', $task)) {
            return false;
        }

        return $candidate === null || $this->canCommit($user, $task, $candidate);
    }

    /**
     * Hand a task that is already assigned to somebody else to a third person.
     *
     * A distinct permission because it is a distinct act: taking work off a named person is a
     * management decision, whereas assigning an unassigned task is scheduling. Both still have to
     * clear canCommit(), or `reassign` would be a way around it.
     */
    public function reassign(User $user, Task $task, ?User $candidate = null): bool
    {
        if ($task->assignee_id === null) {
            return $this->assign($user, $task, $candidate);
        }

        if (! $this->check($user, 'reassign', $task)) {
            return false;
        }

        return $candidate === null || $this->canCommit($user, $task, $candidate);
    }

    /**
     * A task with work hanging off it is cancelled, not deleted.
     *
     * Subtasks would be orphaned, dependents would lose the thing they were waiting for, and the
     * activity trail §59 requires would describe a record nobody can open. The `cancelled` status
     * exists so "this is not happening" never has to mean "destroy the evidence".
     */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->children()->exists() && ! $record->dependents()->exists();
    }

    /**
     * May this actor put this particular person on this particular task? (docs/05 §6)
     *
     * The same three cases as project staffing, narrowest last, because it is the same decision
     * one level down:
     *
     *   ORGANIZATION-WIDE — an operations manager: anyone in the tenant.
     *
     *   PROJECT SCOPE ON THIS TASK'S PROJECT — a project manager holding `task.assign:project`
     *   who is on this project. Their authority is the job, not a slice of the org chart, so they
     *   may task anyone THEY HAVE PULLED ONTO IT — and only those people. The candidate's own
     *   membership is the bound: without it, a project-scoped grant would let a manager put work
     *   on anyone in the company and, because `task.view:assigned` exists, hand them sight of the
     *   record. Staffing the project is a separate, separately-authorized act
     *   (ProjectMemberPolicy::assign), which is where the org-chart bound on WHO can be brought in
     *   is applied.
     *
     *   ORGANIZATIONAL SUBTREE — a department head or lead engineer: their own people and nobody
     *   else's.
     *
     * Anything else is a no, and a candidate outside the tenant or unable to log in is never a
     * person you can task.
     */
    protected function canCommit(User $user, Task $task, User $candidate): bool
    {
        if ((int) $candidate->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! $candidate->canLogIn()) {
            return false;
        }

        if ($this->resolver->hasOrganizationWide($user, 'task.assign')) {
            return true;
        }

        $scopes = $this->resolver->scopesFor($user, 'task.assign');
        $profile = app(ActorProfile::class);

        // Both sides of the project axis: the actor is on this project, and so is the candidate.
        // Falls through rather than returning false, so a department head who also manages a
        // project keeps their org-chart reach over their own people.
        if ($task->project_id !== null
            && $scopes->has(AccessScope::Project)
            && in_array((int) $task->project_id, $profile->projectIds($user), true)
            && in_array((int) $task->project_id, $profile->projectIds($candidate), true)) {
            return true;
        }

        $broadest = $scopes->broadestOrganizational();

        if ($broadest === null) {
            return false;
        }

        $subtree = $profile->subtreeFor($user, $broadest);
        $candidateUnit = $profile->orgUnitId($candidate);

        return $subtree !== null
            && $candidateUnit !== null
            && in_array($candidateUnit, $subtree, true);
    }
}
