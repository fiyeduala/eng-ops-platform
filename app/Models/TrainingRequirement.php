<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a job requires — the other half of the training register.
 *
 * `training_records` says what people HOLD. This says what they are SUPPOSED to hold, and the two
 * read against each other are the training matrix. The distinction is the whole reason this table
 * exists: a missing certificate has no row, so a register of certificates alone can never answer
 * "who has not done their first aid", which is the only question a training matrix is opened for.
 *
 * IT SHARES THE HSE TRAINING MODULE and does not invent a permission of its own. The person who
 * maintains the course catalogue is the person who says which jobs need which course, and a company
 * that had to grant two permissions to describe one idea would grant the wrong one.
 *
 * SCOPE IS EXPLICIT, not inferred from which foreign key is null. A row with `scope = 'position'`
 * and a null position is a configuration mistake the service refuses; a reader working it out from
 * nulls would silently treat it as applying to the whole company.
 */
class TrainingRequirement extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_ORG_UNIT = 'org_unit';

    public const SCOPE_POSITION = 'position';

    public const SCOPES = [self::SCOPE_ORGANIZATION, self::SCOPE_ORG_UNIT, self::SCOPE_POSITION];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'grace_days' => 'integer',
        ];
    }

    /** The HSE training module, reused. See the class docblock. */
    public static function permissionModule(): string
    {
        return 'hse_training';
    }

    // ------------------------------------------------------------------ access

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ relations

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    // ------------------------------------------------------------------ queries

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * Whether this requirement lands on a particular person.
     *
     * Deliberately answered in PHP rather than in SQL. The matrix loads every active requirement
     * once — there are tens of them, not thousands — and then asks this question per employee, which
     * is one query for the whole screen instead of one per person. The alternative reads better and
     * is a hundred queries on a department of fifty (§89).
     */
    public function appliesTo(Employee $employee): bool
    {
        return match ($this->scope) {
            self::SCOPE_POSITION => $this->position_id !== null && $this->position_id === $employee->position_id,
            self::SCOPE_ORG_UNIT => $this->org_unit_id !== null && $this->org_unit_id === $employee->org_unit_id,
            self::SCOPE_ORGANIZATION => true,
            default => false,
        };
    }

    /** How the requirement reads on screen: "Everybody", "Piping", "Site Engineer". */
    public function targetLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_POSITION => (string) ($this->position?->title ?? 'A position that no longer exists'),
            self::SCOPE_ORG_UNIT => (string) ($this->orgUnit?->name ?? 'A department that no longer exists'),
            default => 'Everybody',
        };
    }

    public function displayName(): string
    {
        return trim(($this->course?->name ?? 'Training').' — '.$this->targetLabel());
    }
}
