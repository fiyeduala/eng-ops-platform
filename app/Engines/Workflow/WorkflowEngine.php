<?php

declare(strict_types=1);

namespace App\Engines\Workflow;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Events\ApprovalDecided;
use App\Events\ApprovalRequested;
use App\Events\WorkflowStalled;
use App\Models\Approval;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\Decision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one approval engine (§19–§25, §78, docs/07).
 *
 * Five public methods, and every module in the platform routes through them: start, act, pendingFor,
 * cancel, reassign. Nothing below names a document.
 *
 * THE INVARIANTS, which every method preserves and the tests assert:
 *
 *   1. AT MOST ONE PENDING STAGE per instance per attempt. `current_stage_instance_id` names it.
 *   2. ASSIGNEES ARE FROZEN AT ENTRY. The policy reads the frozen list; nothing re-resolves.
 *   3. A STAGE THAT RESOLVES TO NOBODY STALLS. It is never skipped, never auto-approved.
 *   4. A SKIPPED STAGE IS RECORDED AS SKIPPED, with the reason in words.
 *   5. NOTHING PARTIAL SURVIVES A FAILURE. Every state change runs in one transaction.
 *
 * Invariant 3 is the one people argue about, so: a workflow that cannot find the approver and
 * quietly moves on has produced a document marked "approved" that nobody approved. That is a
 * fabricated record. Halting is inconvenient; fabricating is a scandal.
 *
 * Notifications are fired as EVENTS, never sent from here. The engine must not know who may be told
 * what — that judgement belongs to the dispatcher's §68 filter, which asks the subject's own policy
 * per candidate. An engine that sent its own mail would be a second, unexamined path to disclosure.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly ConditionEvaluator $conditions,
        private readonly AutoActionRunner $autoActions,
        private readonly ActivityRecorder $activity,
        private readonly OrganizationContext $context,
    ) {}

    // ================================================================== starting

    /**
     * Send a record out for approval.
     *
     * Returns null when the module has no usable workflow — which is a legitimate configuration, not
     * an error. A company that approves its procedures by talking to each other in a room should be
     * able to use the register without inventing a route, and the caller decides what "no workflow"
     * means for it (documents go straight to approved).
     *
     * @throws \DomainException if the record is already out for approval
     */
    public function start(Model $subject, User $actor, ?Workflow $workflow = null): ?WorkflowInstance
    {
        $this->assertRoutable($subject);

        if ($subject->currentWorkflowInstance() !== null) {
            throw new \DomainException('This record is already out for approval.');
        }

        $workflow ??= $this->definitionFor($subject);

        if ($workflow === null) {
            return null;
        }

        return DB::transaction(function () use ($subject, $actor, $workflow) {
            $instance = WorkflowInstance::query()->create([
                'organization_id' => $subject->getAttribute('organization_id'),
                'workflow_id' => $workflow->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                // Where the subject lives, so a unit- or project-scoped approver can reach the
                // stages this instance will open. See syncReach() for why it is copied rather
                // than followed.
                'org_unit_id' => $subject->workflowOrgUnitId(),
                'project_id' => $subject->workflowProjectId(),
                'status' => WorkflowInstance::STATUS_RUNNING,
                'attempt' => 1,
                'started_by' => $actor->getKey(),
                'started_at' => now(),
            ]);

            $this->activity->record(
                $subject,
                'workflow_started',
                $actor->name.' submitted '.$this->labelOf($subject).' for approval.',
                ['workflow' => $workflow->name, 'version' => $workflow->version],
                $actor,
            );

            $this->advance($instance, $subject, $actor, fromSequence: 0);

            return $instance->refresh();
        });
    }

    /**
     * Send a rejected record back round, keeping the conversation intact (§24).
     *
     * attempt + 1 on the SAME instance, never a fresh one. Every earlier decision, comment and
     * timing stays attached, so "what were we asked to change last time, and by whom" is one query
     * instead of an archaeology exercise across rows nothing joins.
     */
    public function resubmit(Model $subject, User $actor): ?WorkflowInstance
    {
        $this->assertRoutable($subject);

        $instance = $subject->workflowInstances()
            ->whereIn('status', [
                WorkflowInstance::STATUS_REVISION_REQUESTED,
                WorkflowInstance::STATUS_REJECTED,
                WorkflowInstance::STATUS_STALLED,
            ])
            ->first();

        if ($instance === null) {
            return $this->start($subject, $actor);
        }

        return DB::transaction(function () use ($instance, $subject, $actor) {
            $instance->update([
                'attempt' => $instance->attempt + 1,
                'status' => WorkflowInstance::STATUS_RUNNING,
                'outcome' => null,
                'outcome_reason' => null,
                'completed_at' => null,
                'current_stage_instance_id' => null,
            ]);

            $this->activity->record(
                $subject,
                'workflow_resubmitted',
                $actor->name.' resubmitted '.$this->labelOf($subject).' for approval (attempt '.$instance->attempt.').',
                ['attempt' => $instance->attempt],
                $actor,
            );

            $this->advance($instance, $subject, $actor, fromSequence: 0);

            return $instance->refresh();
        });
    }

    // ================================================================== acting

    /**
     * Record one person's decision and move the route on.
     *
     * AUTHORIZATION IS THE CALLER'S JOB — ApprovalPolicy, through the five gates, before this is
     * reached. What this method DOES enforce is the part a policy cannot see: that the stage is
     * still open, that this person is on the frozen assignee list, and that they have not already
     * voted. Those are integrity rules about the route's own state, and they are re-checked here
     * because a stale browser tab and a double-submitted form both produce a request that passed
     * authorization at the moment it was rendered (§84).
     *
     * @throws \DomainException when the stage is closed, the actor is not assigned, or they already acted
     */
    public function act(
        WorkflowStageInstance $stageInstance,
        User $actor,
        Decision $decision,
        ?string $comment = null,
        ?User $onBehalfOf = null,
    ): void {
        if (! $stageInstance->isPending()) {
            throw new \DomainException('That approval has already been dealt with.');
        }

        $delegator = $onBehalfOf;

        // Being delegated to is the ONE way somebody not on the frozen list may act, and it still
        // requires the delegator to have been on it. Otherwise the freeze would be decorative.
        $assigned = $stageInstance->isAssignedTo($actor)
            || ($delegator !== null && $stageInstance->isAssignedTo($delegator));

        if (! $assigned) {
            throw new \DomainException('This approval is not assigned to you.');
        }

        if ($stageInstance->hasDecisionFrom($actor)) {
            throw new \DomainException('You have already recorded a decision on this approval.');
        }

        DB::transaction(function () use ($stageInstance, $actor, $decision, $comment, $delegator) {
            $approval = Approval::query()->create([
                'organization_id' => $stageInstance->organization_id,
                'workflow_stage_instance_id' => $stageInstance->getKey(),
                'user_id' => $actor->getKey(),
                'delegated_from' => $delegator?->getKey(),
                'decision' => $decision->value,
                'comment' => $comment,
                'acted_at' => now(),
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);

            $instance = $stageInstance->instance()->firstOrFail();
            $subject = $this->subjectOf($instance);

            if ($subject === null) {
                // The record was deleted underneath a pending approval. Close the route rather than
                // leaving a stage pointing at nothing in somebody's inbox forever.
                $this->close($instance, null, WorkflowInstance::STATUS_CANCELLED, 'cancelled', 'The record no longer exists.', $actor);

                return;
            }

            $this->activity->record(
                $subject,
                'approval_'.$decision->value,
                $actor->name.' recorded "'.$decision->label().'" at '.$stageInstance->displayName().'.',
                array_filter(['stage' => $stageInstance->stage_name, 'comment' => $comment]),
                $actor,
            );

            ApprovalDecided::dispatch($stageInstance, $approval, (int) $actor->getKey());

            // A decisive answer closes the stage whatever the quorum says: one rejection is enough
            // to stop a document, and waiting for the other two reviewers to also reject it would
            // be theatre.
            if ($decision->isDecisive()) {
                $this->closeStage($stageInstance, $decision, $actor, $comment);
                $this->handleRejection($instance, $stageInstance, $subject, $actor, $decision, $comment);

                return;
            }

            if ($decision->leavesStagePending()) {
                return;   // a delegation hands the decision on; the stage stays open
            }

            $stage = $stageInstance->stage;
            $required = $stage?->requiredApprovals(count($stageInstance->assigneeIds())) ?? 1;

            if ($stageInstance->approvals()->count() < $required) {
                return;   // quorum not yet met — the stage waits for the others
            }

            $this->closeStage($stageInstance, Decision::Approve, $actor, $comment);
            $this->advance($instance, $subject, $actor, fromSequence: (int) $stageInstance->sequence);
        });
    }

    // ================================================================== the inbox

    /**
     * Everything waiting on this person (docs/07 §8).
     *
     * A query, not a collection, so the caller can filter, sort and paginate it. It narrows to the
     * frozen assignee lists; it does NOT decide access. The caller still runs each row's subject
     * through the policy before showing anything, because a person can be on an assignee list for a
     * record whose confidentiality later changed (§84).
     *
     * @return Builder<WorkflowStageInstance>
     */
    public function pendingFor(User $user): Builder
    {
        return WorkflowStageInstance::query()
            ->pending()
            ->assignedTo($user)
            ->whereHas('instance', fn (Builder $q) => $q->where('status', WorkflowInstance::STATUS_RUNNING));
    }

    public function pendingCountFor(User $user): int
    {
        return $this->pendingFor($user)->count();
    }

    // ================================================================== administration

    /**
     * Hand your own approval to somebody else.
     *
     * NOT the same act as reassignment, and deliberately a different method. Reassigning is
     * administrative — an administrator moving a stuck stage off somebody who left — and takes
     * `approval.reassign`. Delegating is participatory: the person who was asked is choosing who
     * answers in their place, which is what happens when a manager goes on leave for a fortnight.
     *
     * THE FROZEN LIST IS EDITED, NOT BYPASSED. The delegate takes the delegator's place on it and
     * the delegator comes off. Adding without removing would leave two people able to answer one
     * stage, each assuming the other had; leaving the list alone entirely would record a delegation
     * that changed nothing, and the document would sit in the inbox of somebody who is away.
     *
     * The delegation itself is recorded as an Approval row with `decision = delegate`, which is why
     * the delegator cannot then also vote: the unique index on (stage, user) says one answer each,
     * and handing the decision on IS their answer.
     *
     * @throws \DomainException when the stage is closed, the actor is not on it, or they already answered
     */
    public function delegate(
        WorkflowStageInstance $stageInstance,
        User $actor,
        User $to,
        ?string $comment = null,
    ): void {
        if (! $stageInstance->isPending()) {
            throw new \DomainException('That approval has already been dealt with.');
        }

        if (! $stageInstance->isAssignedTo($actor)) {
            throw new \DomainException('This approval is not assigned to you.');
        }

        if ($stageInstance->hasDecisionFrom($actor)) {
            throw new \DomainException('You have already recorded a decision on this approval.');
        }

        if ((int) $to->getKey() === (int) $actor->getKey()) {
            throw new \DomainException('An approval cannot be delegated to yourself.');
        }

        if ((int) $to->organization_id !== (int) $stageInstance->organization_id) {
            throw new \DomainException('That person is not part of this organization.');
        }

        DB::transaction(function () use ($stageInstance, $actor, $to, $comment) {
            Approval::query()->create([
                'organization_id' => $stageInstance->organization_id,
                'workflow_stage_instance_id' => $stageInstance->getKey(),
                'user_id' => $actor->getKey(),
                'delegated_from' => null,
                'decision' => Decision::Delegate->value,
                'comment' => trim('Delegated to '.$to->name.'. '.(string) $comment),
                'acted_at' => now(),
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);

            $assignees = array_values(array_unique(array_filter(
                array_map('intval', $stageInstance->assigneeIds()),
                fn (int $id) => $id !== (int) $actor->getKey(),
            )));

            $assignees[] = (int) $to->getKey();

            $stageInstance->update([
                'resolved_assignees' => array_values(array_unique($assignees)),
                'resolution_note' => mb_substr(
                    $actor->name.' delegated this approval to '.$to->name.'.',
                    0,
                    300,
                ),

                // The clock is not restarted. A delegated approval is still the same approval, and
                // resetting the escalation level would let a stage be kept out of escalation
                // indefinitely by passing it around.
                'last_escalated_level' => $stageInstance->last_escalated_level,
            ]);

            $instance = $stageInstance->instance()->firstOrFail();
            $subject = $this->subjectOf($instance);

            if ($subject !== null) {
                $this->activity->record(
                    $subject,
                    'approval_delegated',
                    $actor->name.' delegated '.$stageInstance->displayName().' to '.$to->name.'.',
                    array_filter(['stage' => $stageInstance->stage_name, 'comment' => $comment]),
                    $actor,
                );
            }

            // The delegate now has something waiting on them, and finds out the same way anybody
            // else on a stage does.
            ApprovalRequested::dispatch($stageInstance->refresh(), (int) $actor->getKey());
        });
    }

    /** Stop a route. The record stays; the approval does not. */
    public function cancel(WorkflowInstance $instance, User $actor, string $reason): void
    {
        if ($instance->isClosed()) {
            return;
        }

        DB::transaction(function () use ($instance, $actor, $reason) {
            $instance->stageInstances()
                ->where('status', WorkflowStageInstance::STATUS_PENDING)
                ->update(['status' => WorkflowStageInstance::STATUS_CANCELLED, 'updated_at' => now()]);

            $subject = $this->subjectOf($instance);

            $this->close($instance, $subject, WorkflowInstance::STATUS_CANCELLED, 'cancelled', $reason, $actor);
        });
    }

    /**
     * Hand a stuck stage to somebody else.
     *
     * REPLACES the frozen list rather than adding to it, and records that it was reassigned and by
     * whom. Adding would leave the original assignees able to act on something they have been taken
     * off, which is how two people approve the same stage and each thinks the other did not.
     *
     * @param  array<int,int>  $userIds
     */
    public function reassign(WorkflowStageInstance $stageInstance, array $userIds, User $actor, ?string $reason = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));

        if ($ids === []) {
            throw new \DomainException('Reassigning an approval requires at least one person.');
        }

        DB::transaction(function () use ($stageInstance, $ids, $actor, $reason) {
            $stageInstance->update([
                'resolved_assignees' => $ids,
                'resolution_note' => mb_substr(
                    'Reassigned by '.$actor->name.($reason !== null ? ': '.$reason : '.'),
                    0,
                    300,
                ),
                'last_escalated_level' => 0,
            ]);

            $instance = $stageInstance->instance()->firstOrFail();
            $subject = $this->subjectOf($instance);

            if ($subject !== null) {
                $this->activity->record(
                    $subject,
                    'approval_reassigned',
                    $actor->name.' reassigned '.$stageInstance->displayName().'.',
                    ['assignees' => $ids],
                    $actor,
                );
            }

            ApprovalRequested::dispatch($stageInstance, (int) $actor->getKey());
        });
    }

    // ================================================================== internals

    /**
     * Open the next applicable stage, or finish the route.
     *
     * The loop is what implements conditional routing: stages whose conditions do not hold are
     * written as SKIPPED with a reason and passed over, so the approval history explains itself.
     * The iteration cap is a safety net against a definition with impossible sequencing — an
     * approval engine that can spin is an approval engine that can take a site down.
     */
    private function advance(WorkflowInstance $instance, Model $subject, User $actor, int $fromSequence): void
    {
        $stages = $instance->workflow
            ->activeStages()
            ->with('conditions')
            ->where('sequence', '>', $fromSequence)
            ->orderBy('sequence')
            ->get();

        foreach ($stages as $stage) {
            if (! $this->conditions->stageApplies($stage, $subject)) {
                $this->recordSkip($instance, $stage, 'The conditions on this stage did not apply to this record.');

                continue;
            }

            $resolution = $this->assignees->resolve($stage, $subject);

            if ($resolution['ids'] === []) {
                $this->stall($instance, $stage, $subject, $resolution['note']);

                return;
            }

            // Before the stage is opened, not after: the assignee list and the reach that decides
            // who may act on it are settled in the same breath, and an opened stage that is briefly
            // unreachable is a race the inbox can observe.
            $this->syncReach($instance, $subject);

            $stageInstance = $this->openStage($instance, $stage, $resolution);

            $this->autoActions->runOnEntry($stage, $subject, $stageInstance, $actor);

            ApprovalRequested::dispatch($stageInstance, (int) $actor->getKey());

            return;
        }

        // Nothing left to ask: the route is complete.
        $this->close($instance, $subject, WorkflowInstance::STATUS_COMPLETED, 'approved', null, $actor);
    }

    /**
     * @param  array{ids:array<int,int>,note:string}  $resolution
     */
    private function openStage(WorkflowInstance $instance, WorkflowStage $stage, array $resolution): WorkflowStageInstance
    {
        $stageInstance = WorkflowStageInstance::query()->create([
            'organization_id' => $instance->organization_id,
            'workflow_instance_id' => $instance->getKey(),
            'workflow_stage_id' => $stage->getKey(),
            'attempt' => $instance->attempt,
            'sequence' => $stage->sequence,
            'stage_name' => $stage->name,
            'status' => WorkflowStageInstance::STATUS_PENDING,
            'resolved_assignees' => $resolution['ids'],
            'resolution_note' => $resolution['note'],
            'entered_at' => now(),
            'due_at' => $stage->hasSla() ? now()->addHours((int) $stage->sla_hours) : null,
        ]);

        $instance->update(['current_stage_instance_id' => $stageInstance->getKey()]);

        return $stageInstance;
    }

    private function recordSkip(WorkflowInstance $instance, WorkflowStage $stage, string $reason): void
    {
        WorkflowStageInstance::query()->create([
            'organization_id' => $instance->organization_id,
            'workflow_instance_id' => $instance->getKey(),
            'workflow_stage_id' => $stage->getKey(),
            'attempt' => $instance->attempt,
            'sequence' => $stage->sequence,
            'stage_name' => $stage->name,
            'status' => WorkflowStageInstance::STATUS_SKIPPED,
            'skip_reason' => mb_substr($reason, 0, 300),
            'entered_at' => now(),
        ]);
    }

    /**
     * Stop, loudly.
     *
     * The stage instance is written as PENDING with an empty assignee list so the stall is visible
     * on the record's approval history in exactly the place a reader would look for it — rather
     * than as an absence they have to notice. An administrator resolves it with reassign(), which
     * fills in the list and carries on.
     */
    private function stall(WorkflowInstance $instance, WorkflowStage $stage, Model $subject, string $note): void
    {
        $stageInstance = WorkflowStageInstance::query()->create([
            'organization_id' => $instance->organization_id,
            'workflow_instance_id' => $instance->getKey(),
            'workflow_stage_id' => $stage->getKey(),
            'attempt' => $instance->attempt,
            'sequence' => $stage->sequence,
            'stage_name' => $stage->name,
            'status' => WorkflowStageInstance::STATUS_PENDING,
            'resolved_assignees' => [],
            'resolution_note' => mb_substr($note, 0, 300),
            'entered_at' => now(),
        ]);

        $instance->update([
            'status' => WorkflowInstance::STATUS_STALLED,
            'current_stage_instance_id' => $stageInstance->getKey(),
        ]);

        $this->activity->record(
            $subject,
            'workflow_stalled',
            'The approval route stopped at "'.$stage->name.'": '.$note,
            ['stage' => $stage->name, 'reason' => $note],
        );

        WorkflowStalled::dispatch($instance, $stageInstance);
    }

    private function closeStage(
        WorkflowStageInstance $stageInstance,
        Decision $decision,
        User $actor,
        ?string $comment,
    ): void {
        $stageInstance->update([
            'status' => match ($decision) {
                Decision::Reject => WorkflowStageInstance::STATUS_REJECTED,
                Decision::RequestRevision => WorkflowStageInstance::STATUS_REVISION_REQUESTED,
                default => WorkflowStageInstance::STATUS_APPROVED,
            },
            'decision' => $decision->value,
            'acted_by' => $actor->getKey(),
            'acted_at' => now(),
            'comment' => $comment,
        ]);
    }

    /**
     * What a no means, which is configuration rather than a fixed rule (§23).
     *
     * `return_to_previous` reopens the stage before this one for the SAME people who saw it,
     * re-resolving nothing — they are being asked to reconsider their own decision, so the frozen
     * list from that stage is exactly the right audience.
     */
    private function handleRejection(
        WorkflowInstance $instance,
        WorkflowStageInstance $stageInstance,
        Model $subject,
        User $actor,
        Decision $decision,
        ?string $comment,
    ): void {
        $onReject = $stageInstance->stage?->on_reject ?? WorkflowStage::ON_REJECT_OWNER;

        if ($decision === Decision::RequestRevision || $onReject === WorkflowStage::ON_REJECT_OWNER) {
            $this->close(
                $instance,
                $subject,
                WorkflowInstance::STATUS_REVISION_REQUESTED,
                'rejected',
                $comment,
                $actor,
            );

            return;
        }

        if ($onReject === WorkflowStage::ON_REJECT_PREVIOUS) {
            // `reorder()` first — see WorkflowInstance::stageInstances(). Without it this finds the
            // EARLIEST approved stage, so rejecting at stage three would reopen stage one and
            // quietly discard stage two's approval along with the person who has to redo it.
            $previous = $instance->stageInstances()
                ->where('attempt', $instance->attempt)
                ->where('sequence', '<', $stageInstance->sequence)
                ->where('status', WorkflowStageInstance::STATUS_APPROVED)
                ->reorder()
                ->orderByDesc('sequence')
                ->first();

            if ($previous !== null) {
                $previous->update([
                    'status' => WorkflowStageInstance::STATUS_PENDING,
                    'decision' => null,
                    'acted_by' => null,
                    'acted_at' => null,
                    'entered_at' => now(),
                ]);

                $previous->approvals()->delete();
                $instance->update(['current_stage_instance_id' => $previous->getKey()]);

                ApprovalRequested::dispatch($previous, (int) $actor->getKey());

                return;
            }
        }

        $this->close($instance, $subject, WorkflowInstance::STATUS_REJECTED, 'rejected', $comment, $actor);
    }

    /**
     * Finish the route and tell the subject what happened.
     *
     * The subject's hook runs INSIDE this transaction on purpose: a document that ends up marked
     * approved while its workflow instance says rejected is the exact disagreement this module
     * exists to prevent, and two transactions is how that disagreement happens.
     */
    private function close(
        WorkflowInstance $instance,
        ?Model $subject,
        string $status,
        string $outcome,
        ?string $reason,
        ?User $actor,
    ): void {
        $instance->update([
            'status' => $status,
            'outcome' => $outcome,
            'outcome_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'completed_at' => now(),
            'current_stage_instance_id' => null,
        ]);

        if ($subject === null) {
            return;
        }

        match ($status) {
            WorkflowInstance::STATUS_COMPLETED => $subject->onWorkflowApproved($instance),
            WorkflowInstance::STATUS_REJECTED => $subject->onWorkflowRejected($instance),
            WorkflowInstance::STATUS_REVISION_REQUESTED => $subject->onWorkflowRevisionRequested($instance),
            WorkflowInstance::STATUS_CANCELLED => $subject->onWorkflowCancelled($instance),
            default => null,
        };

        $this->activity->record(
            $subject,
            'workflow_'.$outcome,
            'The approval route finished: '.$instance->fresh()->statusLabel().'.',
            array_filter(['reason' => $reason]),
            $actor,
        );
    }

    // ------------------------------------------------------------------ lookups

    /**
     * The definition a record enters: whatever the record prefers, else the module default.
     *
     * Only published, active workflows are eligible. A draft workflow is configuration somebody is
     * still writing, and picking up live records mid-thought is how half-built approval chains end
     * up in production.
     */
    private function definitionFor(Model $subject): ?Workflow
    {
        // The record's own veto, asked before anything is looked up. A subject that says it is not
        // routed must not be handed the module default as a consolation prize: "this register needs
        // no approval" is a configuration decision somebody made on a form, and the fallback below
        // exists to serve records with no preference, not to overrule records with one.
        if (! $subject->requiresWorkflow()) {
            return null;
        }

        $preferred = $subject->resolveWorkflowDefinition();

        if ($preferred !== null && $preferred->is_active && $preferred->isPublished()) {
            return $preferred;
        }

        return Workflow::query()
            ->forEntity($subject->workflowEntityType())
            ->usable()
            ->defaults()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Load the record a route is about.
     *
     * Returns null for a deleted subject rather than throwing, because the engine must be able to
     * close routes whose records are gone — and because a nightly escalation job that dies on one
     * orphaned instance stops escalating everything else.
     */
    private function subjectOf(WorkflowInstance $instance): ?Model
    {
        $subject = $instance->subject;

        return $subject instanceof Model && $this->isRoutable($subject) ? $subject : null;
    }

    private function isRoutable(Model $subject): bool
    {
        return method_exists($subject, 'currentWorkflowInstance')
            && method_exists($subject, 'workflowEntityType')
            && method_exists($subject, 'requiresWorkflow');
    }

    /**
     * Copy the subject's current unit and project onto the instance, if either has moved.
     *
     * The values come from `workflowOrgUnitId()` / `workflowProjectId()`, which are the hooks a
     * routable model already overrides to answer "what does a stage mean by the owning department
     * on me" — so stage resolution and approval reach read the same number, and a model with an
     * unusual shape states it once. `assertRoutable()` has already proved the trait is present.
     *
     * WHY COPIED AND NOT FOLLOWED. A subject hangs off `subject_type`/`subject_id`, and the access
     * compiler resolves a missing unit column by `whereHas()` on a parent relation — which cannot
     * walk a morphTo. Teaching it to would mean `whereHasMorph()` and a UNION per subject type on
     * the approvals inbox, the hottest authorized query in the application. Two columns refreshed
     * at each transition answer the same question against an index.
     *
     * WHY REFRESHED RATHER THAN FROZEN. Assignees are frozen at stage entry on purpose — who was
     * asked is a fact about the past. Reach is not: it is the answer to "who may act on this now",
     * and when a document moves department mid-route the people who may act on it are the ones in
     * the department it moved to (§74). Freezing it would leave an in-flight approval addressed to
     * a department that no longer holds the record.
     *
     * Saved only when something actually changed, so a route that advances through six stages does
     * not write six identical rows into the audit trail (§59).
     */
    private function syncReach(WorkflowInstance $instance, Model $subject): void
    {
        $orgUnitId = $subject->workflowOrgUnitId();
        $projectId = $subject->workflowProjectId();

        if ((int) $instance->org_unit_id === (int) $orgUnitId && (int) $instance->project_id === (int) $projectId) {
            return;
        }

        $instance->forceFill([
            'org_unit_id' => $orgUnitId,
            'project_id' => $projectId,
        ])->save();
    }

    private function assertRoutable(Model $subject): void
    {
        if (! $this->isRoutable($subject)) {
            throw new \InvalidArgumentException(
                class_basename($subject).' cannot be routed: it does not use the HasWorkflow trait.'
            );
        }
    }

    private function labelOf(Model $subject): string
    {
        return method_exists($subject, 'workflowLabel')
            ? $subject->workflowLabel()
            : class_basename($subject).' #'.$subject->getKey();
    }
}
