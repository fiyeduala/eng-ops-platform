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
 * What a person does on ONE project (§14, docs/05-project-visibility.md §7).
 *
 * Deliberately not `positions`. A Mechanical Engineer by employment can be the Technical Lead on
 * Project C and an ordinary team member on Project A at the same time, and neither fact belongs
 * on their personnel record. Workflow assignee rules of type `project_role` resolve against this,
 * which is how "route this to the Technical Lead of this project" lands on the right person for
 * each project without a line of project-specific code.
 *
 * Holding a project role grants NO permissions by itself. Permissions come from Roles; this
 * answers "who", not "may they".
 */
class ProjectRole extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    // ------------------------------------------------------------------ access hooks

    public static function permissionModule(): string
    {
        return 'project';
    }

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

    public function scopeLeads(Builder $query): Builder
    {
        return $query->where('is_lead', true);
    }

    public function displayName(): string
    {
        return (string) $this->name;
    }
}
