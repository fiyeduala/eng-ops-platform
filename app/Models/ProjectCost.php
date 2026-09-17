<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One incurred cost, retained as an append-only ledger row. */
class ProjectCost extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'incurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $cost) => throw new \LogicException(
            'Project costs cannot be edited. Correct the source record and post a reversal.'
        ));
        static::deleting(fn () => throw new \LogicException(
            'Project costs cannot be deleted. Post a reversal instead.'
        ));
    }

    public static function permissionModule(): string
    {
        return 'expense';
    }

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q->where('number', 'like', $like)
            ->orWhere('description', 'like', $like));
    }

    public function displayName(): string
    {
        return $this->number.' — '.$this->description;
    }
}
