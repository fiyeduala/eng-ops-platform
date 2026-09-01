<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Workflow\Contracts\SpawnsFromRecord;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something went wrong: an injury, a near miss, a spill, a fire, damage to plant.
 *
 * ONE REGISTER FOR ALL OF THEM, distinguished by `category`, for the same reason RFIs and technical
 * queries share a table: they differ in what happened and agree in everything else — when, where,
 * how bad, who was involved, what we did, what we are going to do. Separate registers would give a
 * company three answers to "how many incidents did we have", none of them the one the board wants.
 *
 * THE MOST IMPORTANT FIELD IS `potential_severity`, NOT `severity`. A plank that fell into an empty
 * walkway did no harm and could have killed somebody, and a register that ranks by what happened
 * puts it below a paper cut and never prompts anybody to fix the scaffold. Both are recorded, and
 * the register's own default view sorts by potential.
 *
 * IT IS RESTRICTED BY DEFAULT. The confidentiality column defaults to `restricted` in the migration
 * rather than being set by whoever fills in the form, because the person logging an incident at
 * eleven at night is not thinking about who can read it, and the safe answer must be the one they
 * get for free (§6).
 *
 * THE PEOPLE ARE ONE TABLE FURTHER IN. Everything here is operational and readable by anybody who
 * runs the register; the injury, the body part and the days lost live on `incident_persons` behind
 * `hse_incident.view_confidential`. Two questions, two answers (§68).
 *
 * THIS IS THE RECORD THAT SPAWNS THE OTHERS. An incident reaching its review stage opens an
 * investigation and a corrective action through the `create_record` workflow auto-action — as
 * configuration, on the builder screen, with no code behind it. See
 * {@see SpawnsFromRecord}.
 */
class Incident extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    /**
     * The starting vocabulary, used until a company edits `hse_incident.categories`.
     *
     * Categories, not types: "environmental" means a reportable spill in oil and gas and a
     * recycling failure in facilities management, and neither company should need a migration to
     * say so. Every one of these lists is read through OptionList, which slugs the labels, so
     * renaming a label never orphans the records already filed under it (§73).
     */
    public const DEFAULT_CATEGORIES = [
        'Injury', 'Near miss', 'Property damage', 'Environmental', 'Fire',
        'Road traffic', 'Security', 'Occupational illness', 'Dangerous occurrence',
    ];

    /**
     * One scale, used for BOTH what happened and what could have happened.
     *
     * Deliberately one list rather than two. A company that ranked actual harm on a different
     * scale from potential harm could not compare the two columns, and comparing them is the
     * entire argument for near-miss reporting.
     */
    public const DEFAULT_SEVERITIES = ['Negligible', 'Minor', 'Moderate', 'Major', 'Catastrophic'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'occurred_at' => 'datetime',
            'reported_at' => 'datetime',
            'reported_to_authority_on' => 'date',
            'closed_at' => 'datetime',
            'is_reportable' => 'boolean',
            'is_lost_time' => 'boolean',
            'lost_time_days' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function people(): HasMany
    {
        return $this->hasMany(IncidentPerson::class)->orderBy('id');
    }

    /** One per incident. See Investigation::spawnFrom() for why that is enforced in PHP. */
    public function investigation(): HasOne
    {
        return $this->hasOne(Investigation::class);
    }

    /**
     * MorphMany, not HasMany: a corrective action can arise from an incident, an investigation, an
     * observation, an inspection or an NCR, and it carries `source_type` so that one register holds
     * all of them (§90). The return type said HasMany, which is a fatal error the moment anything
     * touches the relation — the incident page, or a workflow opening an action against it.
     */
    public function correctiveActions(): MorphMany
    {
        return $this->morphMany(CorrectiveAction::class, 'source')->orderByDesc('id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * Being the person who reported it reaches it — and being NAMED IN IT does not.
     *
     * The second half is the deliberate one. `incident_persons.user_id` is not an assignee column,
     * so being recorded as injured grants no access to the incident record: an injured person's own
     * medical detail sitting in a register they can browse is not a privacy feature, and the people
     * who need to read the record are the ones running the investigation. Personal access to
     * personal data is an HR question, not a reach question (§6).
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['reported_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        // Structured columns only. `severity` and `potential_severity` are what every routing rule
        // anybody writes here branches on — "critical goes to the HSE director" — and `category`
        // separates an environmental spill from a hand injury. Never the description (§61).
        return ['category', 'severity', 'potential_severity', 'is_reportable', 'is_lost_time'];
    }

    /**
     * `hse_incident`, not the `incident` the class name would produce.
     *
     * The default in HasWorkflow derives this from the class basename, which is right for every
     * register whose class name IS its module key. Three registers in Phase 4 are not: this one,
     * risk assessments and training records all carry an `hse_` module key because "Incidents" on
     * its own is ambiguous in an application that also has quality inspections and NCRs.
     *
     * The value has to be the MODULE key rather than anything else, because a workflow definition
     * an administrator saves stores what WorkflowCatalogue::routableEntities() offered — the morph
     * key — and WorkflowEngine later looks the definition up by this method's return. If the two
     * disagreed, a route configured against incidents would simply never be found, and Phase 4's
     * exit criterion would fail silently on somebody's live incident rather than loudly here.
     */
    public function workflowEntityType(): string
    {
        return 'hse_incident';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Incidents and near misses';
    }

    // ------------------------------------------------------------------ state

    public function isClosedOut(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Whether an investigation is still owed.
     *
     * Reads the relation rather than a flag on the incident: a boolean would have to be kept in
     * step with a table that the workflow engine, the register and an administrator can all write.
     */
    public function needsInvestigation(): bool
    {
        return ! $this->isClosedOut() && $this->investigation()->doesntExist();
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeReportable(Builder $query): Builder
    {
        return $query->where('is_reportable', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingInvestigation(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->whereDoesntHave('investigation');
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        // Location is included because "what happened at the tank farm" is how people actually look
        // for an incident. It is a place, not a narrative — the description stays out (§61).
        return ['number', 'title', 'location'];
    }

    /** An incident has no due date of its own; the investigation it opens does. */
    public function registerDueColumn(): ?string
    {
        return null;
    }
}
