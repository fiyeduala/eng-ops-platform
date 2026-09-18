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
 * One line a cycle asks about — a competency, an objective, or a company value.
 *
 * NARROWING IS OPTIONAL AND ADDITIVE. A line with no unit and no position is asked of everybody; one
 * with a position is asked only of people holding that job. It is how "Technical judgement" reaches
 * engineers and "Site safety leadership" reaches supervisors without the company running two cycles.
 *
 * `weight` IS A NUMBER, NOT A PERCENTAGE. The overall rating is a weighted average, so weights need
 * not add to a hundred and nobody has to rebalance every other line to add one. A company that wants
 * percentages can enter them and get the same answer.
 */
class ReviewCycleCompetency extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const KIND_COMPETENCY = 'competency';

    public const KINDS = [self::KIND_COMPETENCY, 'objective', 'value'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public static function permissionModule(): string
    {
        return 'performance';
    }

    public function accessParentRelation(): ?string
    {
        return 'cycle';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ relations

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    /** Whether this line is asked of a particular person. See the class docblock. */
    public function appliesTo(Employee $employee): bool
    {
        if ($this->position_id !== null && $this->position_id !== $employee->position_id) {
            return false;
        }

        if ($this->org_unit_id !== null && $this->org_unit_id !== $employee->org_unit_id) {
            return false;
        }

        return true;
    }

    public function displayName(): string
    {
        return (string) $this->label;
    }
}
