<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\IncidentReported;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Something happened (§27).
 *
 * `reported_at` IS STAMPED ON CREATION, NOT ON A SUBSEQUENT ACTION. Every other register in this
 * phase has a draft state and a submit step; this one does not, and the difference is the point. An
 * incident that sits in somebody's drafts is an incident nobody has been told about, and the gap
 * between `occurred_at` and `reported_at` is a number safety regulators ask for by name. Creating
 * the record IS reporting it.
 *
 * THE EXIT CRITERION LIVES DOWNSTREAM OF THIS CLASS, and deliberately not inside it. Nothing here
 * opens an investigation or raises a corrective action. Those happen because an administrator
 * configured a workflow stage to do them — Investigation and CorrectiveAction implement
 * SpawnsFromRecord, and AutoActionRunner's `create_record` verb calls them. A company whose
 * procedure says a near miss needs no investigation simply does not add the action, and a company
 * that wants two corrective actions on every reportable incident configures two. Putting either in
 * this service would have made that a code change (§32, §73, §90).
 *
 * PEOPLE ARE ADDED THROUGH `addPerson()` RATHER THAN THROUGH `fill()`, because the medical columns
 * are the ones §68 is about and they must never be reachable from a payload that was validated
 * against the incident. The controller authorizes IncidentPersonPolicy separately.
 */
class IncidentService extends RegisterService
{
    protected function entityType(): string
    {
        return 'hse_incident';
    }

    protected function modelClass(): string
    {
        return Incident::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'reported_at', 'reported_by',
            'closed_at', 'closed_by',
            'reported_to_authority_on', 'authority_reference',
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Incident $record */
        $record->reported_at = now();
        $record->reported_by = $actor->getKey();

        // Confidentiality is `restricted` at the schema and stays there unless somebody explicitly
        // widened it on the form. Re-asserting it here covers the paths that never see a form —
        // the seeder, an import, a future integration — because the default that matters is the one
        // that holds when nobody was thinking about it.
        if (blank($record->confidentiality)) {
            $record->confidentiality = 'restricted';
        }
    }

    /** @param array<string,mixed> $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Incident $record */
        IncidentReported::dispatch($record, (int) $actor->getKey());
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Incident $record */
        $severity = $record->severity !== null ? ' ('.str_replace('_', ' ', $record->severity).')' : '';

        return $actor->name.' reported '.$this->label($record).$severity.'.';
    }

    /**
     * Add somebody who was involved.
     *
     * The medical columns are accepted here and only here, so the one place they can be written is
     * a method the controller reached after authorizing IncidentPersonPolicy. `person_name` is
     * required by the schema even when a user is linked: a name recorded at the time survives the
     * account being deactivated, renamed or deleted, and an injury record that resolves to "Unknown
     * user #412" three years later is not a record.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addPerson(Incident $incident, array $attributes, User $actor): IncidentPerson
    {
        return DB::transaction(function () use ($incident, $attributes, $actor) {
            $person = new IncidentPerson(array_diff_key($attributes, array_flip([
                'id', 'organization_id', 'incident_id', 'created_at', 'updated_at',
            ])));

            $person->organization_id = $incident->organization_id;
            $person->incident_id = $incident->getKey();
            $person->save();

            // The NAME is not in the narrative and the medical columns are not in the payload.
            // An activity entry is readable by anybody who can see the incident, which is a wider
            // audience than the one cleared for what happened to this person (§68).
            $this->activity->record(
                $incident,
                'person_added',
                $actor->name.' recorded a person involved in '.$this->label($incident).'.',
                ['involvement' => $person->involvement],
                $actor,
                $this->projectIdOf($incident),
            );

            return $person;
        });
    }

    /**
     * Correct somebody's details.
     *
     * A typo in a name, a treatment upgraded from first aid to medical treatment two days later, a
     * return-to-work date that only exists once somebody has returned to work — all of them are
     * ordinary corrections to a record that is written while the facts are still moving. The
     * alternative, forcing a delete and a re-entry, loses the row's own history for no benefit.
     *
     * `incident_id` and `organization_id` are stripped rather than trusted: a person row is bound to
     * the incident it was created against, and a payload that could move it would let somebody
     * relocate an injury onto a different event (§84).
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updatePerson(IncidentPerson $person, array $attributes, User $actor): IncidentPerson
    {
        return DB::transaction(function () use ($person, $attributes, $actor) {
            $person->fill(array_diff_key($attributes, array_flip([
                'id', 'organization_id', 'incident_id', 'created_at', 'updated_at',
            ])));

            $changed = array_keys($person->getDirty());

            $person->save();

            $incident = $person->incident()->first();

            if ($incident !== null && $changed !== []) {
                // The COLUMN NAMES are recorded, never the values. "Somebody amended the injury
                // detail" is safe for everybody who can read the incident; what the injury is, is
                // not (§68).
                $this->activity->record(
                    $incident,
                    'person_updated',
                    $actor->name.' amended a person involved in '.$this->label($incident).'.',
                    ['fields' => $changed],
                    $actor,
                    $this->projectIdOf($incident),
                );
            }

            return $person;
        });
    }

    /**
     * Take somebody off the incident.
     *
     * A hard delete, because `incident_persons` keeps no recycle bin: the table exists to hold
     * personal and medical detail, and a soft-deleted row holding a named injury is that detail
     * still sitting in the database after somebody was told it had been removed. The activity entry
     * records that a row was removed and by whom, which is the part that has to survive (§59).
     */
    public function removePerson(IncidentPerson $person, User $actor): void
    {
        DB::transaction(function () use ($person, $actor) {
            $incident = $person->incident()->first();

            $person->delete();

            if ($incident !== null) {
                $this->activity->record(
                    $incident,
                    'person_removed',
                    $actor->name.' removed a person from '.$this->label($incident).'.',
                    [],
                    $actor,
                    $this->projectIdOf($incident),
                );
            }
        });
    }

    /**
     * Flag the incident as statutorily reportable, and record the notification once made.
     *
     * Separate from an ordinary edit because it carries a legal deadline, and separate from the
     * status graph because reportability is a fact about the event rather than a stage in a
     * process: an incident can be reportable and closed, or open and not reportable.
     *
     * NO JURISDICTION IS HARD-CODED. Which incidents are reportable, to whom, and within how many
     * days differs by country and by industry, and this application does not pretend to know (§47).
     * A company encodes its own rule as a workflow condition on `is_reportable` and the register
     * carries the reference the authority gave back.
     */
    public function markReportable(
        Incident $incident,
        User $actor,
        ?string $reportedOn = null,
        ?string $reference = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $actor, $reportedOn, $reference) {
            $incident->forceFill(array_filter([
                'is_reportable' => true,
                'reported_to_authority_on' => $reportedOn,
                'authority_reference' => $reference,
            ], fn ($v) => $v !== null));

            $incident->save();

            $this->activity->record(
                $incident,
                'escalated',
                $actor->name.' flagged '.$this->label($incident).' as reportable to the authorities.',
                array_filter([
                    'reported_to_authority_on' => $reportedOn,
                    'authority_reference' => $reference,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($incident),
            );

            return $incident;
        });
    }

    /**
     * File the incident away.
     *
     * The service refuses a close while the investigation is open, in addition to the policy
     * refusing it, because the policy guards the HTTP route and this guards every caller — a
     * workflow auto-action among them. An incident closed with its investigation running is how a
     * company finds out a year later that nobody established why it happened.
     */
    public function close(Incident $incident, User $actor): Incident
    {
        if ($incident->isClosedOut()) {
            throw new \DomainException($incident->number.' is already closed.');
        }

        $investigation = $incident->investigation()->first();

        if ($investigation !== null && ! $investigation->isComplete()) {
            throw new \DomainException(
                $incident->number.' cannot be closed while its investigation is still open.'
            );
        }

        return DB::transaction(function () use ($incident, $actor) {
            $incident->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
            ]);

            $this->moveTo($incident, 'closed');
            $incident->save();

            $this->activity->record(
                $incident,
                'closed',
                $actor->name.' closed '.$this->label($incident).'.',
                [],
                $actor,
                $this->projectIdOf($incident),
            );

            return $incident;
        });
    }

    /** The only route back to editing a closed incident, and it leaves a trail. */
    public function reopen(Incident $incident, User $actor, string $reason): Incident
    {
        if (! $incident->isClosedOut()) {
            throw new \DomainException($incident->number.' is not closed.');
        }

        if (blank($reason)) {
            throw new \DomainException('Reopening an incident must say why.');
        }

        return DB::transaction(function () use ($incident, $actor, $reason) {
            $incident->forceFill(['closed_at' => null, 'closed_by' => null]);

            $this->moveTo($incident, 'under_review');
            $incident->save();

            $this->activity->record(
                $incident,
                'reopened',
                $actor->name.' reopened '.$this->label($incident).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($incident),
            );

            return $incident;
        });
    }
}
