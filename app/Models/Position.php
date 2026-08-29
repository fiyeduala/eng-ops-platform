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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job title. Describes the JOB, not the system access — that is a Role.
 */
class Position extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'is_management' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ------------------------------------------------------------------ access hooks

    /** Positions hang off the org tree (org_unit_id, the default) but never off a project. */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** A job title has no owner and no assignee; reach comes from the unit it sits in. */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }
}
