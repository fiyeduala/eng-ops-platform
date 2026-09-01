<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The people on an incident.
 *
 * REACH IS THE PARENT'S. There is no separate permission for this row and there should not be: a
 * person is part of an incident, and anybody who can open the incident can see that Ada was
 * involved. What they cannot necessarily see is what happened to her — that is `viewConfidential`
 * on the parent, and it is the whole reason this table exists separately (§68).
 *
 * SO THIS POLICY ANSWERS TWO DIFFERENT QUESTIONS. `view` says "may you see this row at all", and it
 * defers entirely to the incident. `viewMedical` says "may you see the injury, the body part, the
 * treatment and the days lost", and it defers to the incident's `viewConfidential`. Views and
 * exports must ask the second one before rendering those columns; asking only the first is how a
 * medical detail ends up on a progress report.
 *
 * WRITING FOLLOWS THE INCIDENT'S `update`, which means a closed incident's people are frozen along
 * with it — the account of who was hurt is part of the account.
 */
class IncidentPersonPolicy extends ScopedPolicy
{
    protected string $module = 'hse_incident';

    public function view(User $user, Model $record): bool
    {
        return $this->throughIncident($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughIncident($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughIncident($user, 'update', $record) ?? parent::delete($user, $record);
    }

    /** Adding a person is editing the incident. */
    public function createFor(User $user, Incident $incident): bool
    {
        return Gate::forUser($user)->allows('update', $incident);
    }

    /**
     * Read the injury, the body part, the treatment, the days lost.
     *
     * Every view and every export that renders those columns must call this. The row itself may be
     * perfectly visible while this is false — that is the normal case, and it is what lets a
     * project manager see that three people were involved without learning any of their diagnoses.
     */
    public function viewMedical(User $user, IncidentPerson $person): bool
    {
        $incident = $this->incidentFor($person);

        return $incident !== null && Gate::forUser($user)->allows('viewConfidential', $incident);
    }

    private function throughIncident(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof IncidentPerson) {
            return null;
        }

        $incident = $this->incidentFor($record);

        return $incident === null ? false : Gate::forUser($user)->allows($ability, $incident);
    }

    private function incidentFor(IncidentPerson $person): ?Incident
    {
        $incident = $person->relationLoaded('incident') ? $person->incident : $person->incident()->first();

        return $incident instanceof Incident ? $incident : null;
    }
}
