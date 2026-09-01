<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Workflow\Contracts\SpawnsFromRecord;
use App\Models\Concerns\RegisterRecord;
use App\Services\InvestigationService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Why it happened.
 *
 * ONE PER INCIDENT, and the check lives in `spawnFrom()` rather than in a unique index. That is a
 * considered trade, not an omission: a unique index on (organization_id, incident_id) counts
 * soft-deleted rows, so an investigation opened against the wrong incident and deleted would block
 * the real one forever. The idempotence check here ignores trashed rows, which is what anybody
 * would expect — and it is the check that matters, because the thing it is guarding against is a
 * workflow stage being re-entered, not two people racing.
 *
 * IMMEDIATE CAUSE AND ROOT CAUSE ARE SEPARATE COLUMNS because separating them is the entire
 * discipline. "The operative did not clip on" is an immediate cause, and an investigation that
 * stops there produces a corrective action reading "remind operatives to clip on" — after which the
 * same incident happens again. The second column is what forces the second question.
 *
 * IT IS OPENED BY CONFIGURATION, NOT BY CODE. Implementing SpawnsFromRecord is what lets an
 * administrator write "when an incident reaches Under Review, open an investigation" on the
 * workflow builder screen. No listener, no `if` in the incident service, and a company whose
 * procedure says a near miss needs no investigation simply does not add the action (§32, §73).
 */
class Investigation extends Model implements SpawnsFromRecord
{
    use RegisterRecord;

    protected $guarded = ['id'];

    /** `investigation.methods` — a house standard, and several firms pick by severity. */
    public const DEFAULT_METHODS = [
        'Five whys', 'Fishbone', 'TapRooT', 'ICAM', 'Bowtie', 'Simple review',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'started_on' => 'date',
            'due_on' => 'date',
            'completed_on' => 'date',
        ];
    }

    // ------------------------------------------------------------------ spawning

    /**
     * Open an investigation into an incident.
     *
     * Three outcomes, and all three are correct in their own case:
     *
     *   - THROWS when the source is not an incident. Nothing else in the application can be
     *     investigated, so a workflow configured to open an investigation from a permit is
     *     misconfiguration — and the runner catches it, logs it and writes it to the source
     *     record's timeline where the person who configured it will see it.
     *
     *   - RETURNS NULL when one already exists. A stage that is re-entered — a report rejected and
     *     resubmitted, an escalation that reopens a stage — runs its auto-actions again, and an
     *     incident must not collect four investigations because it went round twice. Silent,
     *     because nothing is wrong.
     *
     *   - RETURNS THE RECORD otherwise, with the incident's project, discipline, owning unit and
     *     CONFIDENTIALITY carried across. The last one matters: an investigation quotes the medical
     *     detail it was opened over, so inheriting a restricted incident's classification is the
     *     difference between a protected record and a leak (§68).
     *
     * @param  array<string,mixed>  $params
     */
    public static function spawnFrom(Model $source, array $params, ?int $assigneeId, User $actor): ?Model
    {
        if (! $source instanceof Incident) {
            throw new \DomainException('An investigation can only be opened from an incident.');
        }

        if ($source->investigation()->exists()) {
            return null;
        }

        return app(InvestigationService::class)->openFor($source, $params, $assigneeId, $actor);
    }

    public static function spawnLabel(): string
    {
        return 'Investigation';
    }

    // ------------------------------------------------------------------ relationships

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function correctiveActions(): MorphMany
    {
        return $this->morphMany(CorrectiveAction::class, 'source')->orderByDesc('id');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['lead_id'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['method'];
    }

    // ------------------------------------------------------------------ state

    public function isComplete(): bool
    {
        return $this->completed_on !== null;
    }

    /**
     * Whether a root cause has actually been recorded.
     *
     * The service refuses to complete an investigation without one, and this is the predicate
     * behind that refusal. An investigation closed with an immediate cause and nothing else has
     * described the event, not explained it.
     */
    public function hasRootCause(): bool
    {
        return filled($this->root_cause);
    }

    /** @param  Builder<static>  $query */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_on');
    }

    public function registerDueColumn(): ?string
    {
        return 'due_on';
    }
}
