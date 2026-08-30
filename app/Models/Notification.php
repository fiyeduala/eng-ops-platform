<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One notification, already delivered to one person's bell (docs/10).
 *
 * Deliberately NOT Scopeable, and the reason matters. Scopeable models answer "who else may see
 * this record" — the org ladder, the project axis, an ACL grant. A notification has no such
 * question: it belongs to exactly one account and to nobody else, ever, not to that person's
 * manager and not to an administrator. Giving it a scope hook would create a way to read somebody
 * else's bell, which is a surveillance surface for no gain. NotificationController therefore filters
 * on `user_id = auth()->id()` and nothing else, the same way ProfileController does.
 *
 * The tenant scope still applies, because everything does (§75).
 *
 * `subject` is the record this is ABOUT — the one NotificationDispatcher authorized against before
 * the row was written. It is kept so the list can be re-filtered at READ time as well: access is
 * revocable, and somebody removed from a project on Thursday should stop seeing Tuesday's
 * notification about it. A notification is a message, but its contents are the record's, and the
 * record's rules outlive the send (§68).
 */
class Notification extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    // ------------------------------------------------------------------ queries

    /**
     * The only entry point the controller uses.
     *
     * A named scope rather than a where() in the controller, because "notifications for this
     * person" is the security boundary of this whole model and a boundary spelled out in one place
     * is a boundary that can be audited. `unreadCountFor` and the bell go through it too.
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeOfModule(Builder $query, ?string $module): Builder
    {
        return blank($module) ? $query : $query->where('key', 'like', $module.'.%');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('body', 'like', $like));
    }

    // ------------------------------------------------------------------ helpers

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function urgencyTone(): string
    {
        return match ($this->urgency) {
            'urgent' => 'danger',
            'high' => 'warning',
            default => 'neutral',
        };
    }

    /** The module half of the catalogue key — `task` from `task.assigned`. */
    public function module(): string
    {
        return str_contains((string) $this->key, '.')
            ? explode('.', (string) $this->key)[0]
            : (string) $this->key;
    }

    public function icon(): string
    {
        return match ($this->module()) {
            'task' => 'check-square',
            'project' => 'briefcase',
            'memo' => 'file-text',
            'announcement' => 'megaphone',
            'meeting' => 'calendar',
            default => 'bell',
        };
    }
}
