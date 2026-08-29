<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Support\Enums\AclEffect;
use App\Support\Enums\GranteeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Gate 4: an explicit grant or denial on ONE specific record.
 *
 * An `allow` row widens access beyond the holder's normal scope (sharing a document with a
 * person outside the department). A `deny` row removes access they would otherwise have and
 * ALWAYS wins. Rows may expire, which the Expiry engine monitors.
 */
class RecordPermission extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'grantee_type' => GranteeType::class,
            'effect' => AclEffect::class,
            'expires_at' => 'datetime',
        ];
    }

    public function record(): MorphTo
    {
        return $this->morphTo('record');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForRecord(Builder $query, Model $record): Builder
    {
        return $query->where('record_type', $record->getMorphClass())
            ->where('record_id', $record->getKey());
    }

    public function scopeAllowing(Builder $query, string $ability): Builder
    {
        // `manage` implies every other ability, so a manage grant satisfies any check.
        return $query->whereIn('ability', [$ability, 'manage']);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
