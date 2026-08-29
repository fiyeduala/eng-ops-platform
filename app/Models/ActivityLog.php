<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The human-readable record timeline (section 60).
 *
 * Distinct from AuditLog: this is narrative ("Mary uploaded Revision B"), the audit log is
 * field-level forensics. Timeline entries are themselves visibility-filtered — a viewer sees
 * only entries whose subject they may view.
 */
class ActivityLog extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function scopeForRecord(Builder $query, Model $record): Builder
    {
        return $query->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey());
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function actorName(): string
    {
        return $this->causer_name ?: ($this->causer?->name ?? 'System');
    }

    /** Tailwind classes for the timeline dot, chosen by verb family. */
    public function dotClasses(): string
    {
        return match (true) {
            in_array($this->verb, ['approved', 'signed', 'completed', 'closed', 'verified'], true)
                => 'bg-emerald-500',
            in_array($this->verb, ['rejected', 'cancelled', 'deleted', 'overdue'], true)
                => 'bg-rose-500',
            in_array($this->verb, ['submitted', 'requested_revision', 'escalated'], true)
                => 'bg-amber-500',
            in_array($this->verb, ['assigned', 'reassigned', 'shared'], true)
                => 'bg-sky-500',
            default => 'bg-slate-400',
        };
    }
}
