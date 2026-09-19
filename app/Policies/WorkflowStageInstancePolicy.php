<?php

declare(strict_types=1);

namespace App\Policies;

use App\Engines\Access\AccessResolver;
use App\Engines\Access\ScopeCompiler;
use App\Engines\Configuration\SettingsRepository;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One stage of one record's journey — and the class that decides who may approve what.
 *
 * `act` is the most consequential ability in the platform, so it is written out in full rather than
 * left to the gates. Five things must all hold, and each of them has been the cause of a real
 * failure in systems that skipped it:
 *
 *   1. THE STAGE IS STILL PENDING. A decision on a closed stage is a decision on nothing, and
 *      accepting one would let a late approver overwrite the outcome of a route that has moved on.
 *   2. THE ACTOR IS ON THE FROZEN ASSIGNEE LIST. Not "has the permission" — on the list. The engine
 *      resolved and froze that list when the stage opened, so a reorganisation halfway through an
 *      approval cannot silently move the decision to somebody else (§84: the list in the database
 *      decides, never the id in the request).
 *   3. THEY HAVE NOT ALREADY DECIDED. One person, one decision. Otherwise a quorum of three could
 *      be satisfied by one enthusiastic reviewer clicking three times.
 *   4. SELF-APPROVAL IS PERMITTED, OR THEY ARE NOT THE SUBJECT'S OWNER. Approving your own
 *      submission is the single most common audit finding in document control, and a stage can
 *      forbid it — `allow_self_approval` defaults to false for exactly that reason.
 *   5. THEY HOLD `approval.act`. The ordinary permission gate, last, because failing the earlier
 *      four is about this record rather than about them.
 *
 * VIEWING follows the same rule as the instance: whoever may see the subject may see where it is.
 */
class WorkflowStageInstancePolicy extends ScopedPolicy
{
    protected string $module = 'approval';

    public function __construct(
        AccessResolver $resolver,
        ScopeCompiler $compiler,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($resolver, $compiler);
    }

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof WorkflowStageInstance) {
            return parent::view($user, $record);
        }

        if (! $this->sameOrganization($user, $record)) {
            return false;
        }

        $instance = $record->relationLoaded('instance') ? $record->instance : $record->instance()->first();

        return $instance !== null && Gate::forUser($user)->allows('view', $instance);
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    /** The five tests, in order. See the class docblock for why each one is there. */
    public function act(User $user, WorkflowStageInstance $stageInstance): bool
    {
        if (! $this->sameOrganization($user, $stageInstance)) {
            return false;
        }

        if (! $stageInstance->isPending()) {
            return false;
        }

        if (! $stageInstance->isAssignedTo($user)) {
            return false;
        }

        if ($stageInstance->hasDecisionFrom($user)) {
            return false;
        }

        if (! $this->selfApprovalAllowed($user, $stageInstance)) {
            return false;
        }

        return $this->check($user, 'act', $stageInstance);
    }

    /**
     * Hand your decision to somebody else without leaving the route.
     *
     * Only the person actually holding the decision may delegate it, and only if the stage was
     * configured to allow it. Delegation is recorded on the resulting approval — `delegated_from`
     * — so the history says who was asked and who answered, which is the whole point of allowing it
     * at all.
     *
     * TWO SWITCHES, NOT ONE, AND THEY ARE NOT THE SAME SWITCH. `workflow.allow_delegation` is the
     * company saying whether it does this at all; `allow_delegate` on the stage is a route saying
     * whether THIS decision may be handed on. A company that has decided nobody delegates should
     * not have to find every stage of every route and untick it, and — more to the point — should
     * not have that decision quietly undone by whoever builds the next route. The company setting
     * is therefore a veto over the stage flag rather than a default for it.
     */
    public function delegate(User $user, WorkflowStageInstance $stageInstance): bool
    {
        if (! $this->settings->bool('workflow.allow_delegation', true)) {
            return false;
        }

        $stage = $stageInstance->relationLoaded('stage') ? $stageInstance->stage : $stageInstance->stage()->first();

        return $stageInstance->isPending()
            && $stageInstance->isAssignedTo($user)
            && (bool) ($stage?->allow_delegate)
            && $this->check($user, 'delegate', $stageInstance);
    }

    /**
     * Move a stuck stage to different people entirely.
     *
     * Deliberately NOT available to the assignees: this is the administrator's unstick button for
     * the approver who left the company, and letting the current holder redirect their own decision
     * would turn "I do not want to approve this" into "somebody else's problem" without a rejection
     * anybody can see.
     */
    public function reassign(User $user, WorkflowStageInstance $stageInstance): bool
    {
        return $stageInstance->isPending() && $this->check($user, 'reassign', $stageInstance);
    }

    /**
     * Test 4, written out.
     *
     * The subject's owner is asked of the record itself through HasWorkflow, so a module that
     * decides ownership differently — a purchase request owned by its requester rather than its
     * creator — gets the answer it expects without this class knowing anything about it.
     */
    private function selfApprovalAllowed(User $user, WorkflowStageInstance $stageInstance): bool
    {
        $stage = $stageInstance->relationLoaded('stage') ? $stageInstance->stage : $stageInstance->stage()->first();

        if ($stage === null || (bool) $stage->allow_self_approval) {
            return true;
        }

        $instance = $stageInstance->relationLoaded('instance') ? $stageInstance->instance : $stageInstance->instance()->first();
        $subject = $instance?->subject;

        if ($subject === null || ! method_exists($subject, 'workflowOwnerId')) {
            return true;
        }

        return (int) $subject->workflowOwnerId() !== (int) $user->getKey();
    }
}
