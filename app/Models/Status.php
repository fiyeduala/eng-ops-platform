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

/**
 * One state a record of some module may be in (§63).
 *
 * Configuration, not code. Nothing in the application may branch on `$status->key === 'closed'`
 * — a company is free to delete that key and call it "Handed Over" — so behaviour hangs off the
 * `is_initial` / `is_terminal` flags instead, which are structural facts every workflow needs and
 * no company redefines.
 */
class Status extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_initial' => 'boolean',
            'is_terminal' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Is this status one the application's own code moves records into by key?
     *
     * Service code says `moveTo($run, 'approved')` as a string literal, and `moveTo()` looks the key
     * up among ACTIVE statuses and silently does nothing when it is absent. So renaming the key,
     * deleting it or deactivating it does not produce an error — it produces a platform where the
     * workflow engine approves a payroll run and the register still reads Submitted.
     *
     * A company may still rename the LABEL, recolour and reorder a system status, which is what §73
     * actually promises. Statuses a company added itself carry no code dependency and are entirely
     * its own.
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    // ------------------------------------------------------------------ relationships

    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'from_status_id');
    }

    public function transitionsTo(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'to_status_id');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * Company-level reference data, like a location. It belongs to no unit, no project and no
     * person, so a narrower scope would select nothing rather than a subset — and a department
     * head whose task list rendered "status: —" on every row would reasonably call the system
     * broken. Only READING is organization-wide; defining statuses still needs `status.manage`,
     * which no narrow grant reaches.
     */
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

    public function accessOrganizationWideAbilities(): array
    {
        return ['status.view'];
    }

    // ------------------------------------------------------------------ helpers

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return $this->label;
    }

    /**
     * Design-system tone for the badge component. Unknown tokens fall back to neutral rather
     * than reaching the class attribute, so configuration can never inject styling.
     */
    public function tone(): string
    {
        return in_array($this->color, ['neutral', 'brand', 'success', 'warning', 'danger'], true)
            ? $this->color
            : 'neutral';
    }
}
