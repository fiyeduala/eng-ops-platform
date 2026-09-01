<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Incidents (§27).
 *
 * THE CONFIDENTIAL LAYER IS A SEPARATE ABILITY, NOT A SEPARATE SCOPE. `viewConfidential` gates the
 * medical detail on `incident_persons` — the injury, the body part, the treatment, the days lost —
 * and it is checked in addition to `view`, never instead of it. A safety manager runs the register
 * on `view`; only somebody the company has deliberately trusted with `hse_incident.view_confidential`
 * reads what happened to a named person (§6, §68).
 *
 * A CLOSED INCIDENT IS FROZEN. Once `closed_at` is set, update and delete are refused at any scope.
 * An incident report is the document a regulator, an insurer and a court read, and a register in
 * which the account can be quietly revised after the file is closed is a register that proves
 * nothing. A correction after closure is a reopening, which is recorded.
 *
 * DELETION IS NOT AN ORDINARY OPERATION HERE. `delete` is refused outright once an investigation
 * exists — not because the permission is missing, but because deleting the incident an
 * investigation was opened over would leave the investigation explaining nothing. Withdraw it
 * through a status if it was logged in error.
 *
 * INVESTIGATING IS ITS OWN ABILITY. `hse_incident.investigate` is what lets somebody open and run
 * an investigation, and it is deliberately not implied by `edit`: the document controller who
 * corrects a location is not the person who determines a root cause.
 */
class IncidentPolicy extends ScopedPolicy
{
    protected string $module = 'hse_incident';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Incident && $record->isClosedOut()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Incident && ($record->isClosedOut() || $record->investigation()->exists())) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * Read the medical and personal detail attached to the incident.
     *
     * Checked ON TOP of `view`, so it can never widen reach: somebody who cannot see the incident
     * at all does not reach the people on it by holding this. It is the second half of a two-part
     * question — "may you see this record" and "may you see what happened to the people in it" —
     * and having only the first answer is the normal case.
     */
    public function viewConfidential(User $user, Incident $incident): bool
    {
        return $this->check($user, 'view', $incident)
            && $this->check($user, 'view_confidential', $incident);
    }

    /** Open and run the investigation. Not implied by `edit`; see the class docblock. */
    public function investigate(User $user, Incident $incident): bool
    {
        return $this->check($user, 'investigate', $incident);
    }

    /**
     * File the incident away.
     *
     * Refused while an investigation is still running, and that ordering is the substance of the
     * rule rather than tidiness: an incident closed with its investigation open is how a company
     * discovers, a year later, that nobody ever established why it happened.
     */
    public function close(User $user, Incident $incident): bool
    {
        if ($incident->isClosedOut()) {
            return false;
        }

        $investigation = $incident->relationLoaded('investigation')
            ? $incident->investigation
            : $incident->investigation()->first();

        if ($investigation !== null && ! $investigation->isComplete()) {
            return false;
        }

        return $this->check($user, 'close', $incident);
    }

    /** Reopen a closed incident — the only route back to editing, and it leaves a trail. */
    public function reopen(User $user, Incident $incident): bool
    {
        return $incident->isClosedOut() && $this->check($user, 'close', $incident);
    }
}
