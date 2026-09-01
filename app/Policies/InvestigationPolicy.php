<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Why it happened.
 *
 * GOVERNED BY `hse_incident.investigate`, NOT BY A MODULE OF ITS OWN. An investigation is not a
 * separate thing a company decides who may read; it is the analytical half of the incident, and
 * splitting the permission would create the arrangement where somebody can read the root cause of
 * an incident they cannot open. Every ability here maps onto `investigate` except `view`, which
 * maps onto `view` so that anybody who can read the incident can read its conclusions — which is
 * the entire point of writing them down.
 *
 * WAIT. `view` MAPPING TO `hse_incident.view` DOES NOT MEAN EVERY READER SEES EVERYTHING. The
 * investigation carries its own `confidentiality`, defaulted to restricted at the schema, and Gate 5
 * still applies to this record independently of the incident. So the default is: the incident is
 * visible widely, the analysis is visible to the unit that owns it, and a company that wants the
 * opposite configures it.
 *
 * A COMPLETED INVESTIGATION IS FROZEN. `completed_on` is the moment the findings became the
 * company's answer, and answers that can be edited afterwards are not findings. Reopening is
 * `investigate` on a completed record and it is recorded.
 *
 * SPAWNING IS AUTHORIZED AT THE STAGE, NOT HERE. When the workflow engine opens an investigation
 * through `create_record`, it acts as the configuration, not as a user — see
 * Investigation::spawnFrom(). `createFor()` is the human route, and it is the one that asks
 * whether this person may investigate this incident.
 */
class InvestigationPolicy extends ScopedPolicy
{
    protected string $module = 'hse_incident';

    /**
     * Reading follows the incident's `view`; every write is `investigate`.
     *
     * `edit` is deliberately absent from the right-hand side. Somebody granted `hse_incident.edit`
     * to correct a location on a report has not been granted the authority to rewrite a root cause,
     * and mapping update onto `edit` here would have handed them exactly that.
     *
     * @var array<string,string>
     */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'investigate',
        'update' => 'investigate',
        'delete' => 'investigate',
        'restore' => 'investigate',
        'forceDelete' => 'delete_permanently',
    ];

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Investigation && $record->isComplete()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Investigation && $record->isComplete()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Open the investigation for a specific incident. */
    public function createFor(User $user, Incident $incident): bool
    {
        return ! $incident->investigation()->exists()
            && $this->check($user, 'investigate', $incident);
    }

    /**
     * Sign the findings off.
     *
     * Refused without a root cause, and that refusal is the module's reason for existing: an
     * investigation completed with the cause field empty is a closed file that explains nothing,
     * and it is how the same incident happens again in eighteen months.
     */
    public function complete(User $user, Investigation $investigation): bool
    {
        return ! $investigation->isComplete()
            && $investigation->hasRootCause()
            && $this->check($user, 'investigate', $investigation);
    }

    /** Reopen a completed investigation — the only route back to editing. */
    public function reopen(User $user, Investigation $investigation): bool
    {
        return $investigation->isComplete() && $this->check($user, 'investigate', $investigation);
    }
}
