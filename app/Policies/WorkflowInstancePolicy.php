<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One record's journey through one route.
 *
 * THE CENTRAL DECISION: visibility follows the SUBJECT, not the workflow definition. Whether you
 * may see that a drawing is sitting with the chief engineer is a question about the drawing, not
 * about your permission to administer approval routes. Somebody who can open the drawing can see
 * where it is; somebody who cannot must not learn from an approval screen that it exists (§61).
 *
 * That is also why `workflow.view` is not the gate here. A document controller with no workflow
 * administration rights still needs to see the progress of their own submissions, and a workflow
 * administrator has no business reading the approval history of a confidential HR record just
 * because they configured the route it travelled.
 *
 * The instance is never editable. It is a record of what happened, and the engine is the only
 * writer (§59). Cancelling — the one operation that changes a live instance — is its own ability.
 */
class WorkflowInstancePolicy extends ScopedPolicy
{
    protected string $module = 'approval';

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof WorkflowInstance) {
            return parent::view($user, $record);
        }

        // Gate 1 still applies first and unconditionally: a cross-tenant instance is invisible
        // whatever its subject says (§75).
        if (! $this->sameOrganization($user, $record)) {
            return false;
        }

        return $this->canSeeSubject($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    /**
     * Abandon a route that is running.
     *
     * Allowed to whoever submitted the record and to holders of `approval.reassign` — the people
     * who unstick things. Not to the approvers: an approver's way of ending a route is to reject
     * it, which leaves a reason and a name attached. Cancelling leaves the record where it was, so
     * offering it to a reviewer would be a way to make an unwelcome submission quietly go away.
     */
    public function cancel(User $user, WorkflowInstance $instance): bool
    {
        if (! $instance->isRunning() && ! $instance->isStalled()) {
            return false;
        }

        if ((int) $instance->started_by === (int) $user->getKey()) {
            return $this->check($user, 'view', $instance);
        }

        return $this->check($user, 'reassign', $instance);
    }

    /**
     * Restart a stalled route once the configuration behind it has been fixed.
     *
     * Same audience as cancelling, for the same reason: this is unsticking work, not deciding it.
     */
    public function restart(User $user, WorkflowInstance $instance): bool
    {
        return $instance->isStalled() && $this->check($user, 'reassign', $instance);
    }

    /**
     * Can this person see the record the route is carrying?
     *
     * Fails closed when the subject cannot be resolved. A morph pointing at a deleted or unreadable
     * row is precisely the case where guessing is wrong.
     */
    private function canSeeSubject(User $user, WorkflowInstance $instance): bool
    {
        $subject = $instance->relationLoaded('subject') ? $instance->subject : $instance->subject()->first();

        return $subject instanceof Model && Gate::forUser($user)->allows('view', $subject);
    }
}
