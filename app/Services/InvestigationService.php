<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\InvestigationAssigned;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Why it happened.
 *
 * `openFor()` IS THE HALF OF PHASE 4's EXIT CRITERION THAT RUNS. Investigation::spawnFrom() calls
 * it by name when the workflow engine's `create_record` verb fires, which is what makes "when an
 * incident reaches Under Review, open an investigation" a sentence an administrator writes on a
 * configuration screen rather than one a developer writes in PHP (§32, §73).
 *
 * IT IS ALSO THE ORDINARY HUMAN ROUTE. The controller calls the same method after authorizing
 * InvestigationPolicy::createFor(). One path, so a spawned investigation and a hand-opened one are
 * the same record with the same defaults — the alternative is two constructors that drift, and the
 * one nobody exercises is the one that ends up wrong.
 *
 * THE CONFIDENTIALITY IS INHERITED FROM THE INCIDENT, and this is the security decision in the
 * class. An investigation quotes the medical detail, the witness accounts and the immediate cause it
 * was opened over. Defaulting it to `internal` while the incident it explains is `restricted` would
 * be a leak with a service in front of it (§68). If the incident somehow carries nothing, it falls
 * back to `restricted` rather than to the schema-wide default.
 */
class InvestigationService extends RegisterService
{
    protected function entityType(): string
    {
        return 'investigation';
    }

    protected function modelClass(): string
    {
        return Investigation::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'incident_id', 'completed_on'];
    }

    /**
     * Open an investigation into an incident.
     *
     * @param  array<string,mixed>  $params  the configured action's parameters — title, method,
     *                                       due_in_days — or a submitted form's attributes. Both
     *                                       arrive as a plain array on purpose: the workflow
     *                                       builder produces one and so does a form request, and
     *                                       the service should not be able to tell them apart.
     */
    public function openFor(Incident $incident, array $params, ?int $assigneeId, User $actor): Investigation
    {
        return DB::transaction(function () use ($incident, $params, $assigneeId, $actor) {
            $investigation = new Investigation;

            $investigation->organization_id = $incident->organization_id;
            $investigation->incident_id = $incident->getKey();
            $investigation->number = $this->numbers->next($this->entityType());
            $investigation->title = $this->titleFor($incident, $params);

            // The incident's context, carried across whole. An investigation that landed on no
            // project would be invisible to everybody scoped to one — which is most of the people
            // who need to read it.
            $investigation->project_id = $incident->project_id;
            $investigation->discipline_id = $incident->discipline_id;
            $investigation->org_unit_id = $incident->org_unit_id;
            $investigation->confidentiality = $incident->confidentiality ?? 'restricted';

            $investigation->lead_id = $assigneeId;
            $investigation->method = $params['method'] ?? null;
            $investigation->started_on = now()->toDateString();
            $investigation->due_on = $this->dueDateFrom($params);

            $investigation->status_id = $this->statuses->initialFor($this->entityType())?->id;
            $investigation->created_by = $actor->getKey();

            $investigation->save();

            $this->activity->record(
                $investigation,
                'created',
                $actor->name.' opened '.$this->label($investigation).' into '.$incident->number.'.',
                ['incident' => $incident->number, 'method' => $investigation->method],
                $actor,
                $this->projectIdOf($investigation),
            );

            // Recorded on the INCIDENT as well, because the person watching the incident is the one
            // who needs to know an investigation now exists, and they may never open this record.
            $this->activity->record(
                $incident,
                'investigation_opened',
                'An investigation ('.$investigation->number.') was opened into '.$incident->number.'.',
                ['investigation' => $investigation->number],
                $actor,
                $this->projectIdOf($incident),
            );

            if ($assigneeId !== null) {
                InvestigationAssigned::dispatch($investigation, (int) $actor->getKey());
            }

            return $investigation;
        });
    }

    /**
     * Sign the findings off.
     *
     * REFUSES WITHOUT A ROOT CAUSE, in the service as well as the policy, and this is the single
     * rule the module exists to enforce. An investigation completed with `root_cause` empty is a
     * closed file that explains nothing — and the immediate cause, which people fill in readily
     * because it is the easy one, is exactly what produces a corrective action reading "remind
     * operatives to clip on". See Investigation's docblock.
     */
    public function complete(Investigation $investigation, User $actor, ?string $findings = null): Investigation
    {
        if ($investigation->isComplete()) {
            throw new \DomainException($investigation->number.' has already been completed.');
        }

        if ($findings !== null && filled($findings)) {
            $investigation->findings = $findings;
        }

        if (! $investigation->hasRootCause()) {
            throw new \DomainException(
                $investigation->number.' cannot be completed without a root cause. '
                .'An immediate cause on its own does not explain why it happened.'
            );
        }

        return DB::transaction(function () use ($investigation, $actor) {
            $investigation->forceFill(['completed_on' => now()->toDateString()]);

            $this->moveTo($investigation, 'completed');
            $investigation->save();

            $this->activity->record(
                $investigation,
                'completed',
                $actor->name.' completed '.$this->label($investigation).'.',
                [],
                $actor,
                $this->projectIdOf($investigation),
            );

            return $investigation;
        });
    }

    /**
     * Reopen a completed investigation — the only route back to editing it.
     *
     * REQUIRES A REASON, because this is the one action in the module that takes a signed-off
     * explanation of why somebody was hurt and makes it editable again. New evidence, a regulator's
     * question, a root cause that turned out to be a symptom: all legitimate, all worth a sentence
     * in the timeline, and none of them worth losing the record that the file was once closed (§59).
     *
     * `completed_on` is cleared so the register's own predicates agree with the status graph. An
     * investigation showing as reopened while `isComplete()` still answers true would let the parent
     * incident be closed underneath it.
     */
    public function reopen(Investigation $investigation, User $actor, string $reason): Investigation
    {
        if (! $investigation->isComplete()) {
            throw new \DomainException($investigation->number.' is not completed.');
        }

        if (blank($reason)) {
            throw new \DomainException('Reopening an investigation must say why.');
        }

        return DB::transaction(function () use ($investigation, $actor, $reason) {
            $investigation->forceFill(['completed_on' => null]);

            $this->moveTo($investigation, 'in_progress');
            $investigation->save();

            $this->activity->record(
                $investigation,
                'reopened',
                $actor->name.' reopened '.$this->label($investigation).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($investigation),
            );

            return $investigation;
        });
    }

    // ================================================================== internals

    /**
     * A title that reads on a register row.
     *
     * The configured title wins; failing that the incident's own, prefixed. An investigation called
     * "Investigation" in a list of forty investigations is a row nobody can pick out.
     *
     * @param  array<string,mixed>  $params
     */
    private function titleFor(Incident $incident, array $params): string
    {
        $configured = trim((string) ($params['title'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return 'Investigation — '.$incident->title;
    }

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
