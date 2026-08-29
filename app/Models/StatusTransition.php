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
 * A permitted move between two statuses (§63).
 *
 * The table is a whitelist: a move with no row is refused. That is the whole point — a status
 * field with free movement is a text box with extra steps, and every downstream report that says
 * "tasks completed this month" becomes a guess.
 */
class StatusTransition extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'guard' => 'array',
            'requires_comment' => 'boolean',
            'sort' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function from(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }

    // ------------------------------------------------------------------ access hooks

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

    /** "from anywhere" — the shape a Cancel or Reopen transition takes. */
    public function isFromAnywhere(): bool
    {
        return $this->from_status_id === null;
    }

    public function displayName(): string
    {
        return $this->label ?: 'transition #'.$this->getKey();
    }
}
