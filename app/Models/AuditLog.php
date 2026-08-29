<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Append-only technical audit trail (section 59).
 *
 * The model actively REFUSES updates and deletes. Combined with a production DB grant of
 * INSERT+SELECT only, this makes tampering require database-level access, which is itself
 * logged elsewhere. There is deliberately no updated_at column.
 */
class AuditLog extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'is_privileged' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo('auditable');
    }

    public function scopeForRecord(Builder $query, Model $record): Builder
    {
        return $query->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->getKey());
    }

    public function scopeAction(Builder $query, string|array $action): Builder
    {
        return $query->whereIn('action', (array) $action);
    }

    /**
     * The set of fields that actually changed, for compact display.
     *
     * @return array<int,string>
     */
    public function changedKeys(): array
    {
        return array_keys($this->new_values ?? []);
    }

    public function actorName(): string
    {
        return $this->user_name ?: ($this->user?->name ?? 'System');
    }
}
