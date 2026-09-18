<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person's joining or leaving list, copied from a template and ticked off as it is done.
 *
 * THE ITEMS ARE A COPY. `checklist_template_id` is provenance — which list this came from — and
 * nothing reads through it to find the steps. The steps are the rows in `employee_checklist_items`,
 * written when the checklist was started, so a template edited afterwards cannot rewrite what
 * somebody was actually asked to do.
 *
 * COMPLETION IS COMPUTED, NOT CLAIMED. `completed_at` is stamped by the service when the last item
 * is ticked, and there is no way to mark a checklist done with items still open — a half-finished
 * exit list that reports itself complete is how a leaver keeps a laptop.
 */
class EmployeeChecklist extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'checklist';
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id')->withTrashed();
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeChecklistItem::class)->orderBy('sequence');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // ------------------------------------------------------------------ state

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** How many of the copied steps have been ticked, out of how many there are. */
    public function doneCount(): int
    {
        return $this->items->whereNotNull('done_at')->count();
    }

    public function openCount(): int
    {
        return $this->items->whereNull('done_at')->count();
    }

    public function isOverdue(): bool
    {
        return ! $this->isComplete()
            && $this->due_on !== null
            && $this->due_on->isBefore(now()->startOfDay());
    }

    public function displayName(): string
    {
        return ucfirst((string) $this->kind).' checklist';
    }

    // ------------------------------------------------------------------ queries

    /** @param  Builder<static>  $query */
    public function scopeOfKind(Builder $query, ?string $kind): Builder
    {
        return blank($kind) ? $query : $query->where($this->qualifyColumn('kind'), $kind);
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('completed_at'));
    }
}
