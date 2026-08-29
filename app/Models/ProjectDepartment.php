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

/**
 * A department assigned to a project — step one of the §13 cascade.
 *
 * The cascade, in full (docs/05-project-visibility.md §6):
 *
 *   1. Whoever runs the project assigns DEPARTMENTS to it.        ← this table
 *   2. Each department's head sees "projects assigned to my department".
 *   3. The head assigns PEOPLE, from their own subtree only.      ← project_members
 *   4. Those people gain project-axis visibility.
 *
 * Step 1 grants nothing on its own, and that is the point. If attaching Engineering to a project
 * gave all sixty engineers sight of it, the head would have no way to commit four people to a
 * confidential job, and §14's whole distinction between the org axis and the project axis would
 * collapse into "everybody sees everything their department is near".
 */
class ProjectDepartment extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * Attaching or detaching a department is administration OF the project, which §14 puts behind
     * `project.manage` — not behind team permissions, which are about people.
     */
    public static function permissionModule(): string
    {
        return 'project';
    }

    public function accessOwnerColumns(): array
    {
        return ['assigned_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    // org_unit_id and project_id are both real columns here, so both default hooks apply: a
    // department head reaches the rows naming their own unit, and a member reaches the rows on
    // their own project.

    // ------------------------------------------------------------------ helpers

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return ($this->orgUnit?->name ?? 'Department').' on '.($this->project?->displayName() ?? 'project');
    }
}
