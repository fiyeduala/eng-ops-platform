<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a task's checklist.
 *
 * A part of its task rather than a record in its own right: no number, no status, no
 * confidentiality of its own. Every authorization question about it is answered by asking
 * TaskPolicy about the parent, which is why TaskChecklistItemPolicy sets `$module = 'task'`
 * instead of inventing `task_checklist_item.*` permissions that PermissionCatalogue does not
 * declare and no administrator could ever grant.
 *
 * It still carries `organization_id` and uses BelongsToOrganization so the tenant scope applies
 * at this table too. The parent-task check should make that redundant; "should" is not a security
 * property.
 */
class TaskChecklistItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'sort' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** Checklist lines are not assigned to anybody; the task is. */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** No unit and no project column of its own — reach comes from the parent task. */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    public function displayName(): string
    {
        return $this->title ?? 'Checklist item';
    }
}
