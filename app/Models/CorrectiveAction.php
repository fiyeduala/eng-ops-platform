<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Workflow\Contracts\SpawnsFromRecord;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Services\CorrectiveActionService;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What we are going to do about it — and who says it worked.
 *
 * THE REGISTER EVERY OTHER MODULE FEEDS. An action arises from an incident, an investigation, an
 * observation, an inspection finding, an NCR or an audit, and it is polymorphic rather than six
 * nullable foreign keys because otherwise it would be seven the moment a module is added. One
 * register means one answer to the only question anybody asks it: what is outstanding against me,
 * from anywhere.
 *
 * COMPLETION AND VERIFICATION ARE TWO EVENTS BY TWO PEOPLE, and collapsing them into a single
 * "done" flag is how a corrective-action register fills with actions that were marked complete and
 * changed nothing. The assignee says the work is done; somebody holding `corrective_action.verify`
 * says it worked, and `effectiveness` records the answer. A company that never fills that column in
 * cannot tell whether its corrective actions correct anything, which is the only reason to keep the
 * register at all.
 *
 * NOBODY VERIFIES THEIR OWN. The policy refuses it outright at any scope — the same separation of
 * duties change requests use for approval, and for the same reason: an assignee marking their own
 * work effective is not a check, it is a formality with a second timestamp on it.
 *
 * OPENED BY CONFIGURATION. Implementing SpawnsFromRecord is what makes "an accepted NCR opens a
 * corrective action" and "an incident under review opens one" sentences an administrator writes on
 * the workflow builder rather than sentences a developer writes in PHP (§32, §73).
 */
class CorrectiveAction extends Model implements SpawnsFromRecord
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    /** `corrective_action.types`. */
    public const DEFAULT_TYPES = ['Corrective', 'Preventive', 'Immediate containment', 'Improvement'];

    /** `corrective_action.priorities`. */
    public const DEFAULT_PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    /**
     * `corrective_action.effectiveness` — the answer to "did it work", asked at verification.
     *
     * Three values rather than a boolean, because "partially effective" is the honest answer more
     * often than either of the others, and a register that cannot record it records a lie.
     */
    public const DEFAULT_EFFECTIVENESS = ['Effective', 'Partially effective', 'Not effective'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'due_on' => 'date',
            'completed_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ spawning

    /**
     * Open an action arising from another record.
     *
     * IDEMPOTENCE IS PER STAGE, NOT PER SOURCE, and that is the difference from an investigation.
     * One incident legitimately produces five corrective actions; what must not happen is the SAME
     * configured action producing a duplicate because its stage was re-entered. So the check is on
     * the action's title against that source — the title comes from the configuration, so two
     * different configured actions have two different titles and both still open.
     *
     * The source may be anything at all: this is the one register with no opinion about what it
     * arises from, which is why the signature accepts a bare Model where Investigation refuses.
     *
     * @param  array<string,mixed>  $params
     */
    public static function spawnFrom(Model $source, array $params, ?int $assigneeId, User $actor): ?Model
    {
        $title = trim((string) ($params['title'] ?? ''));

        if ($title === '') {
            throw new \DomainException('A corrective action must be configured with a title.');
        }

        $exists = static::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('title', $title)
            ->exists();

        if ($exists) {
            return null;
        }

        return app(CorrectiveActionService::class)->openFrom($source, $params, $assigneeId, $actor);
    }

    public static function spawnLabel(): string
    {
        return 'Corrective action';
    }

    // ------------------------------------------------------------------ relationships

    /** Whatever it arose from. Empty for an action somebody raised on its own. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The person it is assigned to reaches it — which is the point of the register.
     *
     * A site supervisor with `assigned` scope and nothing else still sees every action anybody has
     * given them, from any source, without being able to browse the incident register it came out
     * of. That is the narrowest useful grant in the HSE module and the one most companies hand out
     * the most of.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['assigned_to'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['action_type', 'priority', 'effectiveness'];
    }

    // ------------------------------------------------------------------ state

    public function isComplete(): bool
    {
        return $this->completed_on !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_on');
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query->whereNotNull('completed_on')->whereNull('verified_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeAssignedTo(Builder $query, int|string|null $userId): Builder
    {
        return blank($userId) ? $query : $query->where('assigned_to', $userId);
    }

    public function registerDueColumn(): ?string
    {
        return 'due_on';
    }
}
