<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who was hurt, saw it, or was otherwise part of it.
 *
 * THE MOST SENSITIVE TABLE IN THE APPLICATION. `injury_type`, `body_part`, `treatment` and
 * `days_lost` are medical facts about a named individual, and they are here rather than on
 * `incidents` precisely so that reading the incident and reading the injury are two separate
 * authorization decisions. A safety manager runs the register; only somebody holding
 * `hse_incident.view_confidential` sees this (§6, §68).
 *
 * `user_id` IS NULLABLE AND `person_name` IS NOT. Most people hurt on a construction site have no
 * login — they work for a subcontractor, or they are a member of the public — and a schema that
 * could only record employees would drop exactly the incidents that matter most.
 *
 * BEING NAMED HERE GRANTS NO ACCESS TO ANYTHING. There is no assignee column, and that is
 * deliberate: see Incident::accessAssigneeColumns().
 *
 * Reach is inherited rather than computed — the policy defers to the parent incident — so a person
 * row can never be visible to somebody the incident is not.
 */
class IncidentPerson extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /**
     * Named, because Laravel's pluraliser turns `IncidentPerson` into `incident_people` and the
     * table is `incident_persons`. Not a preference: "persons" is the word the whole module is
     * written in — the migration, the policy, every docblock — and it is the word incident
     * reporting itself uses. Left implicit, the mismatch surfaced only when somebody opened an
     * incident that actually had people on it, as "no such table" on a live page.
     */
    protected $table = 'incident_persons';

    protected $guarded = ['id'];

    /** How this person was caught up in it — `hse_incident.involvements`. */
    public const DEFAULT_INVOLVEMENTS = [
        'Injured', 'Witness', 'First aider', 'Driver', 'Supervisor', 'Member of the public',
    ];

    /**
     * What was done about it — `hse_incident.treatments`.
     *
     * The order is the internationally recognised escalation, because the list doubles as the
     * classification a lost-time figure is argued from. A company may edit it; most will not.
     */
    public const DEFAULT_TREATMENTS = [
        'None', 'First aid', 'Medical treatment', 'Restricted work',
        'Lost time', 'Hospitalised', 'Fatality',
    ];

    protected function casts(): array
    {
        return [
            'days_lost' => 'integer',
            'returned_to_work_on' => 'date',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The name to show — the linked account's, or the text typed for somebody without one. */
    public function displayName(): string
    {
        return $this->relationLoaded('user') && $this->user !== null
            ? (string) $this->user->name
            : (string) $this->person_name;
    }

    /**
     * Whether this row carries anything medical.
     *
     * Used by the show screen to decide whether to render the confidential block at all, so a
     * witness row is not hidden behind a permission for the sake of four empty fields.
     */
    public function hasMedicalDetail(): bool
    {
        return filled($this->injury_type)
            || filled($this->body_part)
            || filled($this->treatment)
            || $this->days_lost !== null;
    }
}
