<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Model;

/**
 * Approval routes — the definitions, not the records travelling through them (§5, §78).
 *
 * This is one of the most powerful screens in the platform, and the permission set says so.
 * Whoever may publish a workflow decides who approves what across the whole company: they can route
 * every purchase around the finance director, or route every document to themselves. `workflow.
 * publish` is therefore separate from `workflow.edit` on purpose, so an organization can let a
 * process analyst draft routes while reserving the act of making one live.
 *
 * A PUBLISHED ROUTE IS NOT DELETABLE. Records in flight need their definition to read stage names
 * and rejection behaviour from, and records that finished years ago need it to explain their own
 * approval history. Deactivating stops new records entering; that is the operation people actually
 * mean when they say delete.
 */
class WorkflowPolicy extends ScopedPolicy
{
    protected string $module = 'workflow';

    /**
     * Make a draft live.
     *
     * The state test matters as much as the permission: publishing an already-published route is
     * not a thing, and offering it would let somebody re-stamp `published_at` on a definition whose
     * approval history is dated against the original.
     */
    public function publish(User $user, Workflow $workflow): bool
    {
        return $workflow->isDraft() && $this->check($user, 'publish', $workflow);
    }

    /** Take a route out of use. New records stop entering it; the ones already inside finish on it. */
    public function deactivate(User $user, Workflow $workflow): bool
    {
        return $workflow->isPublished()
            && $workflow->is_active
            && $this->check($user, 'publish', $workflow);
    }

    /**
     * Fork a published route into version n+1.
     *
     * Shares `workflow.publish` with publishing rather than borrowing `edit`, because a new version
     * is how a published route changes at all — letting `edit` alone reach it would make the
     * separation between drafting and publishing decorative.
     */
    public function version(User $user, Workflow $workflow): bool
    {
        return $workflow->isPublished() && $this->check($user, 'publish', $workflow);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Workflow && ($record->isPublished() || $record->instances()->exists())) {
            return false;
        }

        return parent::delete($user, $record);
    }
}
