<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * What timesheets and overtime share: a record that belongs to one person and is decided by a route.
 *
 * The same shape LeaveService has, named once for the two registers that followed it. Nothing here
 * approves anything — `route()` hands the record to WorkflowEngine, and the engine calls back through
 * the model's hooks into `markApproved()` and friends.
 */
abstract class RoutedTimeService extends RegisterService
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

    /** A plain noun for messages: "timesheet", "overtime request". */
    abstract protected function noun(): string;

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'requester_id', 'org_unit_id', 'confidentiality',
            'submitted_at', 'decided_at', 'decision_reason',
            'cancelled_by', 'cancelled_at', 'cancellation_reason',
        ];
    }

    /**
     * Whose record this is, copied from the personnel file and never from the form: these decide who
     * approves it and which department head can read it.
     */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        $employee = $record->getAttribute('employee_id') === null
            ? null
            : Employee::query()->find($record->getAttribute('employee_id'));

        if ($employee === null) {
            throw new \DomainException('There is no personnel record to put this '.$this->noun().' against.');
        }

        $record->setAttribute('requester_id', $employee->user_id);
        $record->setAttribute('org_unit_id', $employee->org_unit_id);
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
        $this->decide($record, $instance, 'approved', 'approved');
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
