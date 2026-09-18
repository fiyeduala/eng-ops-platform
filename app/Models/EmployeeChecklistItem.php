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
 * One step on one person's list, and — once ticked — who says it was done and when.
 *
 * A TICK IS SOMEBODY'S WORD. That is why `done_by` and `done_at` are recorded and why an item may
 * demand a note: "laptop returned" is worth nothing without the person who took it back. It is also
 * why a checklist is not the clearance check on an exit — the system's own registers know whether an
 * asset is still issued, and that answer survives the person who ticked the box being wrong.
 */
class EmployeeChecklistItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'requires_note' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'checklist';
    }

    public function accessParentRelation(): ?string
    {
        return 'checklist';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
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

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EmployeeChecklist::class, 'employee_checklist_id');
    }

    public function responsibleRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'responsible_role_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function displayName(): string
    {
        return (string) $this->label;
    }
}
