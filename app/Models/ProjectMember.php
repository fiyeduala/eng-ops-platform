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
 * One person on one project — the single source of truth for the project visibility axis (§14).
 *
 * ActorProfile reads this table on every request for every user. Four columns are load-bearing
 * for authorization rather than for reporting: `user_id`, `is_active`, `starts_on` and `ends_on`.
 * A row that is inactive or out of its window grants nothing, which is how §12 time-bounded
 * assignment works without anyone remembering to revoke it.
 *
 * Membership grants REACH, never AUTHORITY. Being on a project lets you see its records; what you
 * may do to them still comes from your roles, and confidentiality above `internal` is untouched
 * by it (docs/05-project-visibility.md §4).
 */
class ProjectMember extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'allocation_percent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function projectRole(): BelongsTo
    {
        return $this->belongsTo(ProjectRole::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    // ------------------------------------------------------------------ access hooks

    /** "My own membership rows" — what a person sees of their own assignments. */
    public function accessOwnerColumns(): array
    {
        return ['user_id', 'added_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['user_id'];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Rows that grant reach today. The same predicate ActorProfile applies, kept here so a
     * screen listing "current team" and the resolver deciding who can see what cannot drift.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    public function isCurrent(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = now()->startOfDay();

        return ! ($this->starts_on !== null && $this->starts_on->gt($today))
            && ! ($this->ends_on !== null && $this->ends_on->lt($today));
    }

    public function isLead(): bool
    {
        return (bool) $this->projectRole?->is_lead;
    }

    public function displayName(): string
    {
        return ($this->user?->name ?? 'Member').' on '.($this->project?->displayName() ?? 'project');
    }
}
