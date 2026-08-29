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
 * How one module's records are numbered (§64).
 *
 * Reference numbers are the language a company uses on paper, in email and on site. They are
 * quoted in contracts and printed on drawings, which is why the pattern is configuration and why
 * an issued number is never reused or renumbered.
 */
class NumberingScheme extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /** @var array<int,string> */
    public const RESET_PERIODS = ['none', 'yearly', 'monthly'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'start_at' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(NumberSequence::class);
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
        return ['numbering.view'];
    }

    // ------------------------------------------------------------------ helpers

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * The bucket the counter lives in for a given moment.
     *
     * Derived rather than stored so that changing `reset_period` on an existing scheme starts a
     * fresh counter for the new period instead of corrupting the old one.
     */
    public function periodKeyFor(\DateTimeInterface $moment): string
    {
        return match ($this->reset_period) {
            'yearly' => $moment->format('Y'),
            'monthly' => $moment->format('Y-m'),
            default => 'all',
        };
    }

    public function displayName(): string
    {
        return $this->label ?: $this->entity_type.' numbering';
    }
}
