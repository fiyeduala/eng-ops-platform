<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * A register whose records are decided by the workflow engine rather than by a button.
 *
 * Leave asked for this shape first, timesheets and overtime repeated it, and a vacancy requisition
 * is the same sentence again with a different noun: a draft somebody sends, a route that decides it,
 * and three outcomes written back through the model's hooks. Naming it once is what keeps Phase 6's
 * exit criterion true — every one of these runs on the SAME engine as documents, and none of them
 * has an `approve()` method or an approve route for anybody to reach past the route with.
 *
 * WHAT A SUBCLASS MAY CHANGE is the vocabulary: `approvedStatusKey()` exists because "approved"
 * means different things to different registers — a timesheet becomes approved, a vacancy becomes
 * OPEN, and a vacancy sitting in a status called "approved" while applications are taken against it
 * is a distinction the reporting cannot make.
 *
 * WHAT IT MAY NOT CHANGE is that the decision arrives from the engine. `route()` hands the record
 * over; everything below it is a callback.
 */
abstract class RoutedRecordService extends RegisterService
{
    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        protected readonly SettingsRepository $settings,
        protected readonly WorkflowEngine $workflow,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    /** A plain noun for messages: "timesheet", "overtime request", "vacancy". */
    abstract protected function noun(): string;

    /** The status a record reaches when its route approves it. */
    protected function approvedStatusKey(): string
    {
        return 'approved';
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'requester_id', 'org_unit_id', 'confidentiality',
            'submitted_at', 'decided_at', 'decision_reason',
            'cancelled_by', 'cancelled_at', 'cancellation_reason',
        ];
    }

    /**
     * Move to `submitted` and start (or resume) the route.
     *
     * The status moves BEFORE the route starts, because a route whose every stage is skipped closes
     * inside `resubmit()` and calls markApproved(), which must not then be overwritten.
     */
    protected function route(Model $record, User $actor, string $narrative): void
    {
        $record->forceFill(['submitted_at' => now(), 'decided_at' => null, 'decision_reason' => null]);
        $this->moveTo($record, 'submitted');
        $record->save();

        $this->activity->record($record, 'submitted', $narrative, [], $actor, $this->projectIdOf($record));

        if ($this->workflow->resubmit($record, $actor) === null) {
            throw new \DomainException(
                'No approval route is configured for '.$this->noun().'s, so this cannot be sent anywhere. Ask an administrator to publish one.'
            );
        }
    }

    // ================================================================== workflow outcomes

    public function markApproved(Model $record, WorkflowInstance $instance): void
    {
        $this->decide($record, $instance, $this->approvedStatusKey(), 'approved');
    }

    public function markRejected(Model $record, WorkflowInstance $instance): void
    {
        $this->decide($record, $instance, 'rejected', 'rejected');
    }

    /** Sent back for changes: a draft its owner can edit and send again. */
    public function markReturned(Model $record, WorkflowInstance $instance): void
    {
        $record->forceFill(['submitted_at' => null]);
        $this->decide($record, $instance, 'draft', 'returned');
    }

    /** Extra work a register does the moment its route approves it. Nothing, for most. */
    protected function afterApproved(Model $record, WorkflowInstance $instance): void {}

    private function decide(Model $record, WorkflowInstance $instance, string $to, string $verb): void
    {
        $record->forceFill([
            'decided_at' => $to === 'draft' ? null : now(),
            'decision_reason' => $instance->outcome_reason,
        ]);
        $this->moveTo($record, $to);
        $record->save();

        $this->activity->record(
            $record,
            $verb,
            $this->label($record).' was '.$verb.' through its approval route.',
            array_filter(['workflow_instance' => $instance->getKey()]),
            null,
            $this->projectIdOf($record),
        );

        if ($verb === 'approved') {
            $this->afterApproved($record, $instance);
        }
    }

    /** Close a route that is still open — running, or stalled with nobody to decide it. */
    protected function closeRoute(Model $record, User $actor, string $reason): void
    {
        $instance = $record->workflowInstances()
            ->whereIn('status', [WorkflowInstance::STATUS_RUNNING, WorkflowInstance::STATUS_STALLED])
            ->first();

        if ($instance !== null) {
            $this->workflow->cancel($instance, $actor, $reason);
        }
    }

    public function requireDraft(Model $record, string $doing): void
    {
        if ($record->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft '.$this->noun().' can be '.$doing.'.');
        }
    }
}
