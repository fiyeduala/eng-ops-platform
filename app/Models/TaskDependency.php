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
 * "This task waits for that one."
 *
 * An edge, not a record: authorization is the waiting task's, which is the side the edge is
 * created from and the side it constrains. The blocking task is checked separately when the edge
 * is created — you may not learn that TSK-000412 exists by being told your task depends on it —
 * but once created the edge belongs to `task_id`.
 *
 * Cycles are refused by TaskDependencyService, not by the schema. A database cannot express
 * "no path from B back to A", and a dependency graph that quietly contains a cycle makes every
 * "can this task start?" question unanswerable.
 */
class TaskDependency extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    // ------------------------------------------------------------------ relationships

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
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

    public function accessAssigneeColumns(): array
    {
        return [];
    }

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
        return ($this->task?->number ?? 'Task').' depends on '.($this->dependsOn?->number ?? 'task');
    }
}
