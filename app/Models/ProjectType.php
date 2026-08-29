<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What kind of work a project is: EPC, Design, Maintenance (§13, §72).
 *
 * A configurable catalogue, not an enum. A facilities-management company and an oil-and-gas EPC
 * contractor have almost nothing in common here, and neither should have to ask a developer.
 */
class ProjectType extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * Governed by the `project` module rather than one of its own. A separate permission for the
     * type list would be a checkbox every administrator has to understand for no gain: whoever
     * configures projects configures the vocabulary projects are described in.
     */
    public static function permissionModule(): string
    {
        return 'project';
    }

    /**
     * A label, organization-wide. Narrowing it to a department would leave a department head
     * looking at a project whose type renders blank.
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['project.view'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ helpers

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return (string) $this->name;
    }
}
