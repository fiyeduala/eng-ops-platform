<?php

declare(strict_types=1);

namespace App\Engines\Workflow;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The "and also do this" half of a stage (§22).
 *
 * A stage can be configured to move the record's status, raise a task, or tell somebody, at the
 * moment it opens. That is what makes a workflow an operating procedure rather than a queue of
 * buttons: "when it reaches the QA stage, put it In Review and raise a task on the QA lead" is one
 * sentence a manager says, and it should be one row of configuration.
 *
 * THREE RULES, and the third is the one that matters:
 *
 *   1. ONLY THE VERBS IN self::VERBS EXIST. An action is a keyed instruction with named parameters,
 *      never a callable, a class name or an expression. There is no path from a text field in the
 *      builder to code execution, which is the whole reason this is a match statement over a fixed
 *      list rather than something clever.
 *
 *   2. NOTIFICATIONS GO THROUGH THE DISPATCHER, which re-asks the subject's own policy for every
 *      candidate (§68). An auto-action cannot be used to tell somebody about a record they are not
 *      cleared to see — the configuration names WHO, the dispatcher decides whether they may hear
 *      it, and it wins.
 *
 *   3. A FAILING AUTO-ACTION MUST NOT TAKE THE APPROVAL WITH IT. Each verb runs in its own
 *      try/catch and logs. The reasoning: the auto-action is a convenience attached to the stage,
 *      while the stage opening is the thing people are waiting on. A misconfigured status key
 *      should cost somebody a puzzled look in the log, not stop every document in the company from
 *      being routed.
 *
 * Rule 3 has a real cost — a silently skipped status move — so the failure is recorded on the
 * record's own timeline as well as the log, where the person wondering "why is this still Draft"
 * will actually be looking.
 */
class AutoActionRunner
{
    /** The complete vocabulary. Nothing outside this list can be configured. */
    public const VERBS = [
        'set_status' => 'Move the record to a status',
        'create_task' => 'Raise a task',
        'notify' => 'Tell somebody',
    ];

    public function __construct(
        private readonly StatusMachine $statuses,
        private readonly NotificationDispatcher $notifications,
        private readonly ActivityRecorder $activity,
        private readonly AssigneeResolver $assignees,
    ) {}

    /**
     * Run everything configured on a stage as it opens.
     *
     * `auto_actions` is a JSON list of `{verb, ...parameters}`. A row naming an unknown verb is
     * ignored rather than fatal, for the same reason an unknown notification key resolves to a
     * default: configuration written by a future version of the builder should degrade, not
     * detonate.
     */
    public function runOnEntry(
        WorkflowStage $stage,
        Model $subject,
        WorkflowStageInstance $stageInstance,
        User $actor,
    ): void {
        foreach ((array) ($stage->auto_actions ?? []) as $action) {
            if (! is_array($action)) {
                continue;
            }

            $verb = (string) ($action['verb'] ?? '');

            if (! array_key_exists($verb, self::VERBS)) {
                continue;
            }

            try {
                match ($verb) {
                    'set_status' => $this->setStatus($action, $subject, $actor),
                    'create_task' => $this->createTask($action, $subject, $stage, $stageInstance, $actor),
                    'notify' => $this->notify($action, $subject, $stage, $stageInstance, $actor),
                };
            } catch (\Throwable $e) {
                $this->recordFailure($subject, $stage, $verb, $e);
            }
        }
    }

    // ------------------------------------------------------------------ the verbs

    /**
     * Move the record to a named status.
     *
     * Goes through StatusMachine, so the whitelist in `status_transitions` still applies: a workflow
     * cannot be configured to make a move the state machine forbids. A refused transition surfaces
     * as a DomainException and is caught by the caller, which is the correct outcome — the two
     * pieces of configuration disagree, and the state machine is the one that wins.
     *
     * @param  array<string,mixed>  $action
     */
    private function setStatus(array $action, Model $subject, User $actor): void
    {
        $key = (string) ($action['status'] ?? '');

        if ($key === '' || $subject->getAttribute('status_id') === null) {
            return;
        }

        $entityType = $this->entityTypeOf($subject);
        $status = $this->statuses->statusesFor($entityType)->first(
            fn (Status $s) => $s->key === $key || (string) $s->id === $key,
        );

        if ($status === null) {
            throw new \DomainException('No status "'.$key.'" is defined for '.$entityType.'.');
        }

        $this->statuses->transition($subject, $status, $actor, 'Moved automatically by the approval route.');
    }

    /**
     * Raise a task attached to the record.
     *
     * Assignment reuses AssigneeResolver rather than taking a raw user id, so "raise a task on the
     * discipline lead" is expressible the same way an approval stage expresses it — one vocabulary
     * for "who", across the whole engine. An unresolvable assignee leaves the task unassigned rather
     * than dropping it: an unassigned task is visible and fixable, a missing one is not.
     *
     * @param  array<string,mixed>  $action
     */
    private function createTask(
        array $action,
        Model $subject,
        WorkflowStage $stage,
        WorkflowStageInstance $stageInstance,
        User $actor,
    ): void {
        $title = trim((string) ($action['title'] ?? ''));

        if ($title === '') {
            $title = 'Action arising from '.$stage->name;
        }

        $assigneeId = $this->resolveOne($action, $stage, $subject);

        $task = new Task([
            'title' => mb_substr($title, 0, 200),
            'description' => 'Raised automatically when '.$this->labelOf($subject)
                .' reached the "'.$stage->name.'" stage of its approval route.',
            'assignee_id' => $assigneeId,
            'project_id' => $subject->getAttribute('project_id'),
            'org_unit_id' => $subject->getAttribute('org_unit_id'),
            'due_on' => isset($action['due_in_days']) && is_numeric($action['due_in_days'])
                ? now()->addDays((int) $action['due_in_days'])->toDateString()
                : null,
        ]);

        $task->organization_id = $subject->getAttribute('organization_id');
        $task->created_by = $actor->getKey();
        $task->status_id = $this->statuses->initialFor('task')?->id;

        if ($assigneeId !== null) {
            $task->assigned_by = $actor->getKey();
            $task->assigned_at = now();
        }

        $task->save();

        $this->activity->record(
            $subject,
            'task_raised',
            'A task was raised automatically: '.$task->title,
            ['task_id' => $task->getKey(), 'stage' => $stage->name],
            $actor,
        );
    }

    /**
     * Tell somebody the record reached this stage.
     *
     * The audience is resolved through AssigneeResolver and then handed to the dispatcher, which
     * asks the SUBJECT's policy per candidate before sending anything. That second check is not
     * redundant with the first: the resolver answers "who does this configuration mean", the
     * dispatcher answers "may this person be told about this record", and only the second is a
     * security decision (§68).
     *
     * The body deliberately carries no content from the record beyond its identifier, because a
     * notification is delivered to an inbox that outlives the permission that justified it.
     *
     * @param  array<string,mixed>  $action
     */
    private function notify(
        array $action,
        Model $subject,
        WorkflowStage $stage,
        WorkflowStageInstance $stageInstance,
        User $actor,
    ): void {
        $ids = $this->resolveMany($action, $stage, $subject);

        if ($ids === []) {
            return;
        }

        $this->notifications->dispatch(
            NotificationMessage::make(
                key: 'approval.requested',
                title: 'For your information: '.$this->labelOf($subject),
                body: mb_substr(
                    (string) ($action['message'] ?? 'This record reached the "'.$stage->name.'" stage of its approval route.'),
                    0,
                    500,
                ),
                actionUrl: null,
            ),
            Audience::user($ids),
            $subject,
            'view',
            $actor,
            $subject->getAttribute('project_id') !== null ? (int) $subject->getAttribute('project_id') : null,
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Resolve an action's "who" using the stage's own vocabulary.
     *
     * A synthetic stage carries the action's assignee_type/assignee_ref through the resolver without
     * touching the persisted stage. Cloning rather than mutating matters: the stage row is live
     * configuration shared by every record in flight, and an in-memory edit that escaped into a save
     * would rewrite somebody else's route.
     *
     * @param  array<string,mixed>  $action
     * @return array<int,int>
     */
    private function resolveMany(array $action, WorkflowStage $stage, Model $subject): array
    {
        $type = $action['assignee_type'] ?? null;

        if ($type === null) {
            return [];
        }

        $synthetic = $stage->replicate();
        $synthetic->assignee_type = $type;
        $synthetic->assignee_ref = $action['assignee_ref'] ?? null;
        // An auto-action informs; it never approves, so excluding the owner would only hide the
        // record from the person most likely to need the message.
        $synthetic->allow_self_approval = true;

        return $this->assignees->resolve($synthetic, $subject)['ids'];
    }

    /** @param array<string,mixed> $action */
    private function resolveOne(array $action, WorkflowStage $stage, Model $subject): ?int
    {
        return $this->resolveMany($action, $stage, $subject)[0] ?? null;
    }

    private function recordFailure(Model $subject, WorkflowStage $stage, string $verb, \Throwable $e): void
    {
        Log::warning('A workflow auto-action failed and was skipped.', [
            'stage_id' => $stage->getKey(),
            'stage' => $stage->name,
            'verb' => $verb,
            'subject' => $subject->getMorphClass().'#'.$subject->getKey(),
            'message' => $e->getMessage(),
        ]);

        try {
            $this->activity->record(
                $subject,
                'auto_action_failed',
                'An automatic step configured on "'.$stage->name.'" could not run: '.$e->getMessage(),
                ['verb' => $verb, 'stage' => $stage->name],
            );
        } catch (\Throwable) {
            // The timeline entry is a courtesy. If even that fails, the log above stands and the
            // approval continues — which is the entire point of this method.
        }
    }

    private function entityTypeOf(Model $subject): string
    {
        return method_exists($subject, 'workflowEntityType')
            ? $subject->workflowEntityType()
            : str(class_basename($subject))->snake()->toString();
    }

    private function labelOf(Model $subject): string
    {
        return method_exists($subject, 'workflowLabel')
            ? $subject->workflowLabel()
            : class_basename($subject).' #'.$subject->getKey();
    }
}
