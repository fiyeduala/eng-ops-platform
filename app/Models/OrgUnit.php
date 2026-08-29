<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Support\Enums\OrgUnitType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the configurable organization tree (section 3).
 *
 * `path` is a materialized ancestor list, inclusive of self ("/1/7/23/"), maintained by
 * OrgUnitService. Subtree queries are therefore one indexed LIKE, not a recursive CTE — which
 * matters because the access engine walks subtrees on every request.
 */
class OrgUnit extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OrgUnitType::class,
            'depth' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * A unit IS the unit it belongs to. Without this override the compiler would look for an
     * `org_unit_id` column that does not exist here, and every unit-level scope would compile to
     * "no rows" — an administrator with department reach could not see their own department.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return 'id';
    }

    /** Structure is not project data; project membership grants no reach over the org tree. */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /**
     * Units are not owned by an individual. `own` scope on org_unit therefore reaches the
     * viewer's own unit through the unit axis above, not through a creator column.
     */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /**
     * Empty on purpose. The natural candidate, head_employee_id, holds an EMPLOYEE id, and the
     * compiler compares assignee columns against the USER id — matching the two would hand a
     * user whichever unit happens to be headed by the employee sharing their id number. The
     * "unit I head" case is expressed below, against the right key.
     */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** "Assigned to me" for a unit means "I am its head". */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $employeeId = $user->employee?->id;

        if ($employeeId !== null) {
            $query->orWhere($this->qualifyColumn('head_employee_id'), $employeeId);
        }
    }

    // ------------------------------------------------------------------ tree queries

    /** Every unit at or beneath this one, via the materialized path. */
    public function scopeSubtree(Builder $query): Builder
    {
        return $query->where('path', 'like', $this->path.'%');
    }

    public function scopeOfType(Builder $query, OrgUnitType|string $type): Builder
    {
        return $query->where('type', $type instanceof OrgUnitType ? $type->value : $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Ancestor ids parsed from the materialized path, nearest last, excluding self. */
    public function ancestorIds(): array
    {
        $ids = array_values(array_filter(array_map('intval', explode('/', (string) $this->path))));

        return array_values(array_filter($ids, fn (int $id) => $id !== $this->id));
    }

    public function isDescendantOf(self $other): bool
    {
        return $this->id !== $other->id && str_starts_with((string) $this->path, (string) $other->path);
    }

    /** Path segment used for display: "Engineering › Mechanical". */
    public function breadcrumbLabel(string $separator = ' › '): string
    {
        $ancestors = static::whereIn('id', $this->ancestorIds())->orderBy('depth')->pluck('name');

        return $ancestors->push($this->name)->implode($separator);
    }
}
