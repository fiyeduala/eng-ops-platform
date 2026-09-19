<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Going and looking: a site walk, a scaffold check, a housekeeping audit.
 *
 * THE SCORE IS KEPT AS TWO NUMBERS, NOT A PERCENTAGE. A percentage cannot be re-aggregated —
 * averaging the score of a twelve-item inspection with a ninety-item one produces a figure that
 * means nothing — and it cannot express "not applicable". Keeping achieved and possible separately
 * lets a month of inspections roll into one honest number and lets an item be skipped without
 * inflating the result, which is what a boolean pass/fail column silently does.
 *
 * FINDINGS BECOME CORRECTIVE ACTIONS, IN THE CORRECTIVE ACTION REGISTER. Nothing here tracks who
 * fixed what: an inspection that grew its own action list would be the second place to look for
 * outstanding work, and the whole design of this phase is that there is one (§90).
 */
class HseInspection extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $table = 'hse_inspections';

    protected $guarded = ['id'];

    /** `hse_inspection.types`. */
    public const DEFAULT_TYPES = [
        'Site walk', 'Scaffold', 'Housekeeping', 'Plant and equipment', 'Fire',
        'Environmental', 'Welfare', 'Audit',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'scheduled_for' => 'date',
            'performed_at' => 'datetime',
            'closed_at' => 'datetime',
            'score_achieved' => 'decimal:2',
            'score_possible' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function items(): HasMany
    {
        return $this->hasMany(HseInspectionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
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

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['performed_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['inspection_type'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'HSE inspections';
    }

    // ------------------------------------------------------------------ state

    public function wasPerformed(): bool
    {
        return $this->performed_at !== null;
    }

    /**
     * The compliance figure, or null when there is nothing to divide.
     *
     * Null rather than zero when no scorable items were answered: an inspection nobody has scored
     * yet is not 0% compliant, and a register that reports it as such makes its own averages
     * useless.
     */
    public function compliancePercent(): ?float
    {
        $possible = (float) ($this->score_possible ?? 0);

        if ($possible <= 0.0) {
            return null;
        }

        return round(((float) $this->score_achieved / $possible) * 100, 1);
    }

    public function failedItemCount(): int
    {
        return $this->items->where('action_required', true)->count();
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('performed_at')->whereNotNull('scheduled_for');
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'location'];
    }

    public function registerDueColumn(): ?string
    {
        return 'scheduled_for';
    }
}
