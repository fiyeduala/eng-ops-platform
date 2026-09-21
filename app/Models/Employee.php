<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Models\Concerns\HasCustomFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The personnel record (section 4).
 *
 * `supervisor_id` is the configurable reporting line (section 86) — an arbitrary-depth
 * self-reference, never a hard-coded chain.
 */
class Employee extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasCustomFields, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hired_on' => 'date',
            'probation_ends_on' => 'date',
            'contract_ends_on' => 'date',
            'exited_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Units this employee heads. */
    public function headedUnits(): HasMany
    {
        return $this->hasMany(OrgUnit::class, 'head_employee_id');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * An employee record is "owned" by the person it describes, so a General Employee granted
     * employee.view at `own` scope sees their own profile and nothing else.
     */
    public function accessOwnerColumns(): array
    {
        return ['user_id'];
    }

    public function accessProjectColumn(): ?string
    {
        return null;   // employees are not project-attached
    }

    /** A supervisor counts as "assigned" to their direct reports. */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $supervisorId = $user->employee?->id;

        if ($supervisorId !== null) {
            $query->orWhere($this->qualifyColumn('supervisor_id'), $supervisorId);
        }
    }

    // ------------------------------------------------------------------ helpers

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('employment_status', '!=', 'exited');
    }

    /**
     * The personnel register's own idea of what a search term matches.
     *
     * Lives here rather than in EmployeeController so that the index page and the global search
     * box run one rule instead of two that agree today (§61). Names, the employee number and the
     * work email — the five things somebody types when they are looking for a person. Personal
     * contact details, addresses and next of kin are deliberately absent: a search box that
     * matched a home address would answer, one query at a time, questions the employee's own
     * record is guarded against answering.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where($this->qualifyColumn('first_name'), 'like', $like)
            ->orWhere($this->qualifyColumn('last_name'), 'like', $like)
            ->orWhere($this->qualifyColumn('display_name'), 'like', $like)
            ->orWhere($this->qualifyColumn('employee_no'), 'like', $like)
            ->orWhere($this->qualifyColumn('work_email'), 'like', $like));
    }

    public function fullName(): string
    {
        return $this->display_name
            ?: trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
    }

    /** Walk the reporting line upward. Depth-capped so a bad cycle cannot hang a request. */
    public function chainUpward(int $maxDepth = 10): array
    {
        $chain = [];
        $current = $this->supervisor;
        $seen = [$this->id];

        while ($current !== null && count($chain) < $maxDepth && ! in_array($current->id, $seen, true)) {
            $chain[] = $current;
            $seen[] = $current->id;
            $current = $current->supervisor;
        }

        return $chain;
    }

    public function hasSignature(): bool
    {
        return filled($this->signature_path);
    }
}
