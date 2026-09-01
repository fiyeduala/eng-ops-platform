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
 * One checkpoint on one inspection.
 *
 * `acceptance_criteria` AND `measured_value` ARE SEPARATE COLUMNS, and both are text. An inspection
 * record that says only "pass" is worthless the moment somebody disputes it two years later: the
 * criterion is what the specification demanded, the measurement is what was found, and only the
 * pair survives an argument. They are strings rather than numbers because a torque figure, a
 * concrete slump, a coating thickness and a bolt count are not one type, and forcing them into one
 * would be forcing most of them into a comment field.
 *
 * Reach is inherited from the parent inspection.
 */
class QualityInspectionItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    /** `quality_inspection.item_results`. */
    public const DEFAULT_RESULTS = ['Accepted', 'Rejected', 'Not applicable'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class, 'quality_inspection_id');
    }

    public function hasFailed(): bool
    {
        return $this->result === 'fail' || $this->result === 'rejected';
    }
}
