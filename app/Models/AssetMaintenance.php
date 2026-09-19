<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work done to an asset, or work that should have been.
 *
 * ONE TABLE FOR PLANNED AND UNPLANNED, because the difference between them is a flag and a date,
 * and every question a maintenance department is actually asked — what has this machine cost, how
 * long has it been down, who did the work, how often does this fail — is asked across both. A
 * breakdown is a maintenance record whose plan never existed; a service is one whose plan did.
 * Split into two tables, every report becomes a UNION and the one number that matters — the ratio
 * between them — becomes a join.
 *
 * DUE BY DATE OR BY ODOMETER, AND BOTH ARE OPTIONAL. A generator is serviced every 250 running
 * hours, a pickup every 5,000 km, an air conditioner every six months. One column cannot say all
 * three. Whichever threshold arrives first is what makes the work due, which is why `isDue()` reads
 * both and is happy with either.
 *
 * `downtime_hours` IS ENTERED, NOT COMPUTED, and that is a deliberate exception to the rule this
 * phase otherwise keeps about derived values. `completed_at - started_at` is elapsed time, which
 * counts the weekend the workshop was shut and the fortnight the part was in customs. Availability
 * is a number people are held to in front of a client, so it is entered by the person who knows.
 *
 * THE COST POSTS TO NOTHING YET. `labour_cost` and `parts_cost` are recorded and reported on;
 * Part E gives them a `project_costs` posting. That is stated here rather than hidden, because a
 * half-wired foreign key that looks connected is worse than an honest gap.
 */
class AssetMaintenance extends Model
{
    use HasCustomFields, RegisterRecord;

    /**
     * `asset_maintenance.types`.
     *
     * What kind of work it was — NOT whether anybody saw it coming, which is `is_planned` and is a
     * column rather than a word precisely because the whole analysis turns on it (§73).
     */
    public const DEFAULT_TYPES = [
        'Routine service', 'Repair', 'Statutory inspection', 'Calibration', 'Overhaul', 'Tyre change',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'is_planned' => 'boolean',
            'due_on' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'downtime_hours' => 'decimal:2',
            'labour_cost' => 'decimal:2',
            'parts_cost' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    // ------------------------------------------------------------------ access + workflow

    public function accessParentRelation(): ?string
    {
        return 'asset';
    }

    public function scopeForProject(Builder $query, int|string|null $projectId): Builder
    {
        return blank($projectId) ? $query : $query->whereHas('asset', fn (Builder $asset) => $asset->where('project_id', $projectId));
    }

    /**
     * The person who did the work reaches the record of it.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['performed_by'];
    }

    /**
     * No owning department column of its own.
     *
     * A maintenance record inherits its reach from the ASSET — the workshop does not own the
     * generator, the department holding it does — and a column here would let a job be filed under
     * a unit that has nothing to do with the machine.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    /** Nor a project. Which job the machine is on is the asset's answer, and it changes. */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['is_planned', 'maintenance_type', 'asset_id'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Asset maintenance';
    }

    // ------------------------------------------------------------------ state

    /**
     * Whether the work is now owed, by either measure.
     *
     * `$odometer` is the reading to compare against and is optional, because most assets have none.
     * Closed work is never due — a completed service is not owed however old its due date is, and
     * the naive version reports every historical job as overdue forever.
     */
    public function isDue(?int $odometer = null): bool
    {
        if ($this->isClosed()) {
            return false;
        }

        if ($this->due_on !== null && ! $this->due_on->isAfter(now()->startOfDay())) {
            return true;
        }

        return $this->due_odometer !== null
            && $odometer !== null
            && $odometer >= (int) $this->due_odometer;
    }

    /**
     * What it cost in total, or null when neither figure has been entered.
     *
     * Null rather than zero, and the distinction is worth the branch: a job with no costs recorded
     * has not been priced, while a job that genuinely cost nothing is a warranty repair. Reporting
     * the first as zero makes a fleet look cheaper than it is.
     */
    public function totalCost(): ?float
    {
        if ($this->labour_cost === null && $this->parts_cost === null) {
            return null;
        }

        return (float) $this->labour_cost + (float) $this->parts_cost;
    }

    /** Whether the asset is off the road because of this job. */
    public function isInProgress(): bool
    {
        return $this->started_at !== null && $this->completed_at === null && ! $this->isClosed();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('due_on'))
            ->whereDate($this->qualifyColumn('due_on'), '>=', now()->startOfDay())
            ->whereDate($this->qualifyColumn('due_on'), '<=', now()->startOfDay()->addDays($days));
    }

    /**
     * Owed and not done. Deliberately excludes closed work — see isDue().
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('due_on'))
            ->whereDate($this->qualifyColumn('due_on'), '<', now()->startOfDay())
            ->whereHas('status', fn (Builder $q) => $q->where('is_terminal', false));
    }

    /** @param  Builder<static>  $query */
    public function scopeUnplanned(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_planned'), false);
    }

    /** @param  Builder<static>  $query */
    public function scopeForAsset(Builder $query, int|string|null $assetId): Builder
    {
        return blank($assetId) ? $query : $query->where($this->qualifyColumn('asset_id'), $assetId);
    }

    // ------------------------------------------------------------------ presentation

    public function displayName(): string
    {
        return trim($this->reference.' — '.$this->title, ' —');
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['reference', 'title', 'work_order_reference'];
    }

    public function registerDueColumn(): ?string
    {
        return 'due_on';
    }
}
