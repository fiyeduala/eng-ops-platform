<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Projects (§13, §14).
 *
 * Everything structural is inherited: the five gates, the org-axis ∪ project-axis union, and the
 * confidentiality rule that membership does not lift. What is added here is the distinction §14
 * insists on and that a CRUD policy would lose — being able to SEE a project is not being able to
 * ADMINISTER it, and neither is being able to see its money.
 */
class ProjectPolicy extends ScopedPolicy
{
    protected string $module = 'project';

    /**
     * Administer the project itself: dates, departments, type, closure.
     *
     * Separate from `update` because the two answer different questions. A site administrator may
     * correct a description; changing which departments are committed to the job re-shapes who
     * can staff it, and that is a project-management act.
     */
    public function manage(User $user, Project $project): bool
    {
        return $this->check($user, 'manage', $project);
    }

    /** Contract value, budgets, cost — visible only with the explicit financial permission. */
    public function viewFinancials(User $user, Project $project): bool
    {
        return $this->check($user, 'view_financials', $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->check($user, 'archive', $project);
    }

    /**
     * A project with a team, work or history on it is archived, never deleted.
     *
     * Deleting it would take its tasks, documents and activity with it, and the audit trail §59
     * requires would be describing records nobody can open. `archive` exists precisely so that
     * "we are done with this" does not have to mean "destroy the evidence".
     */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->members()->exists() && ! $record->departments()->exists();
    }
}
