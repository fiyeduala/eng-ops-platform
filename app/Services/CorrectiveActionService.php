<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CorrectiveActionAssigned;
use App\Models\CorrectiveAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What we are going to do about it.
 *
 * `openFrom()` IS THE OTHER HALF OF PHASE 4's EXIT CRITERION. CorrectiveAction::spawnFrom() calls it
 * when a configured `create_record` action fires, so "when an incident reaches Under Review, raise a
 * corrective action assigned to the HSE manager, due in 14 days" is configuration (§32, §73).
 *
 * WHAT IS COPIED FROM THE SOURCE, AND WHAT DELIBERATELY IS NOT. Copied: the project, the discipline
 * and the owning unit, because an action with no context is invisible to everybody scoped to one.
 * NOT copied: the source's title, description or confidentiality. That last omission is the
 * considered one.
 *
 * An action is assigned to the person who has to do the work, and that person is frequently not
 * cleared to read the incident it came from — a site supervisor told to install a guard rail does
 * not need, and should not get, the injury detail. So the action carries its own configured title
 * and its own `internal` classification, and inheriting a restricted incident's classification here
 * would have hidden the action from the very person it was assigned to. The action says what to do;
 * the source says what happened (§68).
 *
 * IT IS THE ONE REGISTER THAT ACCEPTS ANY SOURCE. Incidents, investigations, observations,
 * inspections, NCRs, audits — and whatever Phase 5 adds — because the polymorphic column is what
 * keeps "what is outstanding against me" a single question with a single answer (§90).
 */
class CorrectiveActionService extends RegisterService
{
    protected function entityType(): string
    {
        return 'corrective_action';
    }

    protected function modelClass(): string
    {
        return CorrectiveAction::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'source_type', 'source_id',
            'completed_on', 'completed_by', 'completion_notes',
            'verified_at', 'verified_by', 'verification_notes', 'effectiveness',
        ];
    }

    /**
     * Open an action arising from another record.
     *
     * @param  array<string,mixed>  $params  the configured action's parameters: title (required),
     *                                       description, action_type, priority, due_in_days.
     */
    public function openFrom(Model $source, array $params, ?int $assigneeId, User $actor): CorrectiveAction
    {
        $title = trim((string) ($params['title'] ?? ''));

        if ($title === '') {
            throw new \DomainException('A corrective action must be configured with a title.');
        }

        return DB::transaction(function () use ($source, $params, $assigneeId, $actor, $title) {
            $action = new CorrectiveAction;

            $action->organization_id = $source->getAttribute('organization_id') ?? $actor->organization_id;
            $action->source_type = $source->getMorphClass();
            $action->source_id = $source->getKey();

            $action->number = $this->numbers->next($this->entityType());
            $action->title = $title;
            $action->description = $params['description'] ?? null;

            $action->project_id = $source->getAttribute('project_id');
            $action->discipline_id = $source->getAttribute('discipline_id');
            $action->org_unit_id = $source->getAttribute('org_unit_id');

            $action->action_type = $params['action_type'] ?? null;
            $action->priority = $params['priority'] ?? null;
            $action->assigned_to = $assigneeId;
            $action->due_on = $this->dueDateFrom($params);

            // Its own classification, never the source's. See the class docblock.
            $action->confidentiality = 'internal';

            $action->status_id = $this->statuses->initialFor($this->entityType())?->id;
            $action->created_by = $actor->getKey();

            $action->save();

            $this->activity->record(
                $action,
                'created',
                $actor->name.' opened '.$this->label($action).'.',
                array_filter([
                    'source' => $source->getAttribute('number'),
                    'priority' => $action->priority,
                    'due_on' => $action->due_on?->toDateString(),
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($action),
            );

            // On the source's timeline too, because the person watching the incident wants to see
            // that something is being done about it without opening another register.
            $this->activity->record(
                $source,
                'action_raised',
                'Corrective action '.$action->number.' was raised: '.$action->title,
                ['corrective_action' => $action->number],
                $actor,
                $this->projectIdOf($action),
            );

            if ($assigneeId !== null) {
                CorrectiveActionAssigned::dispatch($action, (int) $actor->getKey());
            }

            return $action;
        });
    }

    /** Hand the action to somebody else. */
    public function assign(CorrectiveAction $action, ?int $assigneeId, User $actor): CorrectiveAction
    {
        $previous = $action->assigned_to === null ? null : (int) $action->assigned_to;

        if ($previous === $assigneeId) {
            return $action;
        }

        return DB::transaction(function () use ($action, $assigneeId, $actor) {
            $action->forceFill(['assigned_to' => $assigneeId]);
            $action->save();

            $this->activity->record(
                $action,
                'assigned',
                $actor->name.' assigned '.$this->label($action).'.',
                [],
                $actor,
                $this->projectIdOf($action),
            );

            if ($assigneeId !== null) {
                CorrectiveActionAssigned::dispatch($action, (int) $actor->getKey());
            }

            return $action;
        });
    }

    /**
     * Report the action done.
     *
     * Notes are REQUIRED. "Complete" with nothing beside it is the entry a verifier cannot check
     * and an auditor cannot accept; saying what was actually done is the smallest possible bar and
     * it is the one that makes verification a real act rather than a second tick.
     */
    public function complete(CorrectiveAction $action, User $actor, string $notes): CorrectiveAction
    {
        if ($action->isComplete()) {
            throw new \DomainException($action->number.' has already been completed.');
        }

        if (blank($notes)) {
            throw new \DomainException('Completing an action must say what was done.');
        }

        return DB::transaction(function () use ($action, $actor, $notes) {
            $action->forceFill([
                'completed_on' => now()->toDateString(),
                'completed_by' => $actor->getKey(),
                'completion_notes' => $notes,
            ]);

            $this->moveTo($action, 'completed');
            $action->save();

            $this->activity->record(
                $action,
                'completed',
                $actor->name.' completed '.$this->label($action).'.',
                [],
                $actor,
                $this->projectIdOf($action),
            );

            return $action;
        });
    }

    /**
     * Confirm independently that it was done, and say whether it worked.
     *
     * The service refuses the assignee and the completer as well as the policy, because the policy
     * guards HTTP and this guards every caller. An `ineffective` verdict is a legitimate outcome and
     * moves the action back into play rather than closing it — a register that could only record
     * success would have nothing to say about the actions that did not fix anything, which is the
     * only reason to keep effectiveness at all.
     */
    public function verify(
        CorrectiveAction $action,
        User $actor,
        string $effectiveness,
        ?string $notes = null,
    ): CorrectiveAction {
        if (! $action->isComplete()) {
            throw new \DomainException($action->number.' has not been completed yet.');
        }

        if ($action->isVerified()) {
            throw new \DomainException($action->number.' has already been verified.');
        }

        if ((int) $action->assigned_to === (int) $actor->getKey()
            || (int) $action->completed_by === (int) $actor->getKey()) {
            throw new \DomainException('An action cannot be verified by the person who carried it out.');
        }

        $ineffective = $effectiveness === 'ineffective' || $effectiveness === 'not_effective';

        if ($ineffective && blank($notes)) {
            throw new \DomainException('Recording an action as ineffective must say why.');
        }

        return DB::transaction(function () use ($action, $actor, $effectiveness, $notes, $ineffective) {
            $action->forceFill([
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'verification_notes' => $notes,
                'effectiveness' => $effectiveness,
            ]);

            $this->moveTo($action, $ineffective ? 'ineffective' : 'verified');
            $action->save();

            $this->activity->record(
                $action,
                'verified',
                $actor->name.' verified '.$this->label($action).' as '.str_replace('_', ' ', $effectiveness).'.',
                ['effectiveness' => $effectiveness],
                $actor,
                $this->projectIdOf($action),
            );

            return $action;
        });
    }

    /**
     * Withdraw an action that is not going to happen.
     *
     * THE STATUS IS `cancelled`, NOT `closed`, AND THERE IS NO `closed`. A verified action is
     * already at the end of its road — `verified` is the terminal status — so the only thing left
     * for a close to mean is "this action is being abandoned", and calling that "closed" would put
     * abandoned actions in the same bucket as ones that were carried out and checked. An HSE
     * register whose closure rate counts withdrawals as successes is worse than no register.
     *
     * REQUIRES A REASON. A corrective action raised off an incident and then quietly dropped is the
     * failure mode this whole module exists to prevent, so the reason goes on the timeline where an
     * auditor reading the incident will find it (§59).
     */
    public function cancel(CorrectiveAction $action, User $actor, string $reason): CorrectiveAction
    {
        if ($action->isVerified()) {
            throw new \DomainException($action->number.' has already been verified and cannot be withdrawn.');
        }

        if (blank($reason)) {
            throw new \DomainException('Withdrawing an action must say why.');
        }

        return DB::transaction(function () use ($action, $actor, $reason) {
            $this->moveTo($action, 'cancelled');
            $action->save();

            $this->activity->record(
                $action,
                'cancelled',
                $actor->name.' withdrew '.$this->label($action).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($action),
            );

            // Written on the SOURCE too — the incident, observation, inspection or NCR that
            // produced it. Somebody watching that record needs to know the action it spawned is
            // not going to happen, and they may never open this one.
            $source = $action->source()->first();

            if ($source instanceof Model) {
                $this->activity->record(
                    $source,
                    'action_cancelled',
                    'Corrective action '.$action->number.' was withdrawn.',
                    ['corrective_action' => $action->number, 'reason' => $reason],
                    $actor,
                    $this->projectIdOf($source),
                );
            }

            return $action;
        });
    }

    // ================================================================== internals

    /**
     * @param  array<string,mixed>  $params
     */
    private function dueDateFrom(array $params): ?string
    {
        if (filled($params['due_on'] ?? null)) {
            return (string) $params['due_on'];
        }

        $days = (int) ($params['due_in_days'] ?? 0);

        return $days > 0 ? now()->addDays($days)->toDateString() : null;
    }
}
