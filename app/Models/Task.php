<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Models\Concerns\HasCustomFields;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A unit of work (§16, §88).
 *
 * The first model that reaches people down both visibility axes at once, and it needs no bespoke
 * resolution code to do it: `project_id`, `assignee_id` and `org_unit_id` are the columns the
 * Scopeable defaults already name, so a Lead Engineer with `task.manage:team` sees their team's
 * tasks on the org ladder while a seconded consultant with `task.view:project` sees the same
 * project's tasks through membership — one compiler, one definition of reach (§90).
 *
 * Three deliberate decisions:
 *
 *   - Assigning a task is a GRANT. `task.view:assigned` is a real seeded permission, so writing
 *     `assignee_id` hands that person sight of this record. TaskPolicy::assign() therefore asks
 *     two questions — may I touch this task, and may I reach that person — exactly as
 *     ProjectMemberPolicy does for team membership.
 *   - Followers are NOT reach. A follower row is a notification subscription and nothing else;
 *     see the Follower model. Reading it as an access path would let anyone who can name a
 *     record subscribe themselves into it.
 *   - `linked` (the morph to an incident, an NCR, a document) carries no authorization meaning
 *     either. A task about a confidential incident is reachable on the task's own columns and its
 *     own confidentiality — never on the parent's, which the actor may not be able to open.
 */
class Task extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasCustomFields, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'start_on' => 'date',
            'due_on' => 'date',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'progress_percent' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function linked(): MorphTo
    {
        return $this->morphTo();
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort')->orderBy('id');
    }

    /** Edges where THIS task is the waiter: the tasks it is blocked by. */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    /** Edges where this task is the blocker: what waits on it. */
    public function dependents(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'depends_on_task_id');
    }

    public function followers(): MorphMany
    {
        return $this->morphMany(Follower::class, 'followable');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * "Mine" is a task I raised. Being assigned one is `assigned`, one rung wider — the trait
     * default handles it, and collapsing the two would give an `own`-scoped grant everything an
     * `assigned`-scoped grant has.
     */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    // ------------------------------------------------------------------ helpers

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('number', 'like', $like));
    }

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->title);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isClosed(): bool
    {
        return (bool) $this->status?->is_terminal;
    }

    /**
     * Blocking predecessors that have not finished. Empty means this task is free to move; the
     * service consults it before allowing a transition into a terminal status.
     *
     * @return Collection<int,Task>
     */
    public function blockingDependencies(): Collection
    {
        return $this->dependencies()
            ->with('dependsOn.status')
            ->get()
            ->map(fn (TaskDependency $edge) => $edge->dependsOn)
            ->filter(fn (?Task $task) => $task !== null && ! $task->isClosed())
            ->values();
    }

    public function checklistProgress(): int
    {
        $items = $this->checklistItems;

        if ($items->isEmpty()) {
            return 0;
        }

        return (int) round($items->where('is_done', true)->count() / $items->count() * 100);
    }

    public function daysRemaining(): ?int
    {
        return $this->due_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->due_on->startOfDay(), false);
    }

    public function isOverdue(): bool
    {
        $remaining = $this->daysRemaining();

        return $remaining !== null && $remaining < 0 && ! $this->isClosed();
    }
}
