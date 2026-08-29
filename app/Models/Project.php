<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project (§13, §14).
 *
 * The pivot of the second visibility axis. `accessProjectColumn()` returns the primary key, which
 * is what makes "I can see the projects I am a member of" fall out of the same ScopeCompiler that
 * serves every other model — rather than a special case in a project controller that the search
 * page, the export and the API would each have to remember to copy (§90).
 *
 * Two things a project deliberately does NOT do:
 *
 *   - Membership does not lift confidentiality. A `restricted` project is reachable from its
 *     owning unit subtree or an ACL row; being on the team is not enough (docs/05 §4).
 *   - The department rows on a project grant nobody sight of it. They are the §13 cascade: they
 *     tell a department head which projects to staff, and the staffing is what grants reach.
 */
class Project extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'planned_start' => 'date',
            'planned_end' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
            'contract_value' => 'decimal:2',
            'progress_percent' => 'integer',
            'archived_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(ProjectDepartment::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** The people on the project, as accounts. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['project_role_id', 'org_unit_id', 'starts_on', 'ends_on', 'is_active'])
            ->withTimestamps();
    }

    /** The org units assigned to the project, as units. */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(OrgUnit::class, 'project_departments')
            ->withPivot(['lead_employee_id', 'is_active'])
            ->withTimestamps();
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * The project axis, for the project itself: the record IS the project, so reach is a straight
     * `id IN (my project ids)`.
     */
    public function accessProjectColumn(): ?string
    {
        return $this->getKeyName();
    }

    /**
     * "Mine" is the project I run or sponsor, plus anything I created. Not membership — that is
     * `assigned`, one rung wider, and collapsing the two would hand every team member of every
     * project whatever an `own`-scoped grant allows.
     */
    public function accessOwnerColumns(): array
    {
        return ['created_by', 'manager_id', 'sponsor_id'];
    }

    /**
     * "Assigned to me" on a project means membership. There is no assignee column: a project has
     * a team, and the team is a table.
     */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function applyAssignedScope(Builder $query, User $user): void
    {
        $today = now()->toDateString();

        $query->orWhereExists(function ($sub) use ($user, $today) {
            $sub->selectRaw('1')
                ->from('project_members')
                ->whereColumn('project_members.project_id', $this->qualifyColumn($this->getKeyName()))
                ->where('project_members.user_id', $user->id)
                ->where('project_members.is_active', true)
                ->where(fn ($q) => $q->whereNull('project_members.starts_on')
                    ->orWhere('project_members.starts_on', '<=', $today))
                ->where(fn ($q) => $q->whereNull('project_members.ends_on')
                    ->orWhere('project_members.ends_on', '>=', $today));
        });
    }

    // ------------------------------------------------------------------ helpers

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('number', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->orWhere('client_name', 'like', $like));
    }

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->name);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isClosed(): bool
    {
        return (bool) $this->status?->is_terminal;
    }

    /** Is this user on the team today? Used by policies and by the member picker. */
    public function hasMember(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()))
            ->exists();
    }

    /**
     * Days remaining against the planned end date. Negative means overdue; null means the project
     * has no planned end, which is not the same as being on time.
     */
    public function daysRemaining(): ?int
    {
        return $this->planned_end === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->planned_end->startOfDay(), false);
    }

    public function isOverdue(): bool
    {
        $remaining = $this->daysRemaining();

        return $remaining !== null && $remaining < 0 && ! $this->isClosed();
    }
}
