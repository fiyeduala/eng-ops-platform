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
 * One line on a safety checklist.
 *
 * `result` IS A CONFIGURABLE LIST AND NOT A BOOLEAN, because "not applicable" is a real and frequent
 * answer — there is no scaffold on this floor, the lifting gear was not in use today — and a boolean
 * forces it to be recorded as a pass. That is precisely how an inspection scores full marks on items
 * nobody looked at, and it is the single most common defect in checklist software.
 *
 * `score_possible` IS PER ITEM for the same reason. A not-applicable line contributes nothing to
 * either half of the fraction, so it drops out of the percentage instead of quietly boosting it.
 *
 * Reach is inherited from the parent inspection.
 */
class HseInspectionItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    /**
     * `hse_inspection.results`.
     *
     * "Not applicable" earns its place: an item that does not apply has to be recordable without
     * counting against the score, which is the whole reason the score is kept as achieved over
     * possible rather than as a percentage.
     */
    public const DEFAULT_RESULTS = ['Compliant', 'Non-compliant', 'Not applicable', 'Observation'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'action_required' => 'boolean',
            'score_achieved' => 'decimal:2',
            'score_possible' => 'decimal:2',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(HseInspection::class, 'hse_inspection_id');
    }

    /** Whether this line counts towards the inspection's score at all. */
    public function isScored(): bool
    {
        return $this->score_possible !== null && (float) $this->score_possible > 0.0;
    }
}
