<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What somebody noticed before it became an incident.
 *
 * ANONYMITY IS REAL HERE OR IT IS WORTHLESS. When `is_anonymous` is set, the service leaves
 * `observed_by` NULL rather than filling it in and hiding it on the screen — a hidden-but-present
 * column is a promise that lasts exactly as long as it takes somebody with database access to go
 * looking, and the entire value of a near-miss programme rests on people believing the promise. The
 * cost is that an anonymous observation cannot be followed up with its author; every safety scheme
 * worth running has already made that trade.
 *
 * POSITIVE OBSERVATIONS SHARE THE REGISTER. `observation_type` covers safe behaviour and good
 * practice alongside unsafe acts and conditions, because a register that only ever records failures
 * teaches people that reporting is punishment, and the reporting stops.
 *
 * IT IS THE CHEAPEST RECORD IN THE APPLICATION TO CREATE, deliberately. Almost every column is
 * nullable: somebody standing on a scaffold with a phone gets a title, a type and a photo in, and
 * anything the form insisted on is a reason not to bother.
 */
class HseObservation extends Model
{
    use RegisterRecord;

    protected $table = 'hse_observations';

    protected $guarded = ['id'];

    /**
     * `hse_observation.types` — and the positive entries are not decoration.
     *
     * Safe behaviour and good practice sit in the same list as unsafe acts because a register
     * that only ever records failure teaches people that reporting is punishment, and a near-miss
     * programme nobody files into is worth nothing.
     */
    public const DEFAULT_TYPES = [
        'Unsafe act', 'Unsafe condition', 'Safe behaviour', 'Good practice', 'Environmental',
    ];

    /** `hse_observation.categories`. */
    public const DEFAULT_CATEGORIES = [
        'Housekeeping', 'PPE', 'Work at height', 'Lifting operations', 'Electrical',
        'Plant and equipment', 'Excavation', 'Hot work', 'Chemicals', 'Traffic', 'Environment',
    ];

    /** `hse_observation.risk_levels`. */
    public const DEFAULT_RISK_LEVELS = ['Low', 'Medium', 'High', 'Critical'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'observed_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_anonymous' => 'boolean',
            'action_required' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function correctiveActions(): MorphMany
    {
        return $this->morphMany(CorrectiveAction::class, 'source')->orderByDesc('id');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The observer reaches their own observation — when there is one.
     *
     * An anonymous row has a null `observed_by`, so this grants nothing and the record is reachable
     * only through the ordinary register scopes. That is the correct outcome and not an accident:
     * anonymity that could be undone by asking the access engine who can see the record would not
     * be anonymity.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['observed_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['observation_type', 'category', 'risk_level', 'action_required'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'HSE observations';
    }

    // ------------------------------------------------------------------ state

    public function isClosedOut(): bool
    {
        return $this->closed_at !== null;
    }

    /** Who to show as the observer. Never a name, and never a blank, when the report is anonymous. */
    public function observerLabel(): string
    {
        if ($this->is_anonymous) {
            return 'Reported anonymously';
        }

        return (string) ($this->observer?->name ?? $this->observer_name ?? 'Not recorded');
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeNeedingAction(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->where('action_required', true);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'location'];
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }
}
