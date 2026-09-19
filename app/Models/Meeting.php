<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\AccessResolver;
use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Models\Concerns\HasCustomFields;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A meeting, its minutes, its decisions and the work it produced (§19).
 *
 * No publication column, unlike memos and announcements: a scheduled meeting is real the moment it
 * is scheduled, and hiding it from the people expected to attend would defeat the point. The
 * curtain that does exist here is on the MINUTES — `minutes_circulated_at` — and it is enforced in
 * the presentation layer rather than the scope compiler, because it hides one field of a record
 * everyone concerned can already see, not the record itself. See minutesVisibleTo().
 *
 * Actions arising are ordinary Tasks pointed back here through `linked_type`/`linked_id` (§90).
 * They are deliberately NOT a private list on this model: an action item is work, and work belongs
 * on the assignee's task list, in their notifications, on the calendar and in the overdue report —
 * all of which already exist and none of which would ever look inside a `meeting_actions` table.
 */
class Meeting extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasCustomFields, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'minutes_recorded_at' => 'datetime',
            'minutes_circulated_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function minutesRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minutes_recorded_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MeetingDecision::class)->orderBy('sequence')->orderBy('id');
    }

    /**
     * Action items: Tasks raised from this meeting.
     *
     * A morphMany over the same `linked` morph Task already declares. Reading these still goes
     * through Task's own authorization — the meeting page lists only the actions the reader could
     * have found on the task list anyway, because a task's confidentiality is its own (see Task).
     */
    public function actions(): MorphMany
    {
        return $this->morphMany(Task::class, 'linked');
    }

    // ------------------------------------------------------------------ access hooks

    /** Called it, or created the record. Either reaches it with no other grant. */
    public function accessOwnerColumns(): array
    {
        return ['created_by', 'organizer_id'];
    }

    /**
     * Being invited reaches the meeting — the same grant a task assignment is.
     *
     * @param  Builder<static>  $query
     */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $query->orWhereExists(function ($sub) use ($user) {
            $sub->selectRaw('1')
                ->from('meeting_participants')
                ->whereColumn('meeting_participants.meeting_id', $this->qualifyColumn($this->getKeyName()))
                ->where('meeting_participants.user_id', $user->id);
        });
    }

    // ------------------------------------------------------------------ minutes

    /**
     * May this person read the minutes yet?
     *
     * Uncirculated minutes are a draft in somebody's hands. Everyone who was in the room can see
     * that the meeting happened; what they cannot see is a half-written record of what was said
     * until the person responsible releases it. Once circulated it is simply part of the meeting.
     *
     * Note this is a FIELD-level rule, not a record-level one, which is why it lives here and not
     * in the scope compiler: the meeting is reachable either way, so hiding it in SQL would remove
     * the whole record from people entitled to see the appointment.
     */
    public function minutesVisibleTo(?User $user): bool
    {
        if ($user === null || $this->minutes === null) {
            return false;
        }

        if ($this->minutes_circulated_at !== null) {
            return true;
        }

        // The last clause is the chair: somebody cannot sensibly be asked to decide whether a
        // document may be released and simultaneously be forbidden to read it.
        //
        // Asked of the AccessResolver rather than through `$user->can()`. No gate is defined for a
        // bare permission key — the Gate handles POLICY abilities — so `can('meeting.circulate')`
        // is silently false for everybody, including the person holding it. The failure mode is the
        // quiet kind: no error anywhere, just a chair who is shown an empty minutes panel and a
        // circulate button, and who has no way to tell whether the secretary has written anything.
        //
        // Breadth is all that is asked here, deliberately. Whether this actor reaches THIS meeting
        // is a record question and is answered before the field question ever arises — see
        // MeetingPolicy::viewMinutes(), which composes the two in that order.
        return $user->getKey() === $this->minutes_recorded_by
            || $user->getKey() === $this->organizer_id
            || $user->getKey() === $this->created_by
            || app(AccessResolver::class)->has($user, 'meeting.circulate');
    }

    public function hasMinutes(): bool
    {
        return filled($this->minutes);
    }

    public function minutesCirculated(): bool
    {
        return $this->minutes_circulated_at !== null;
    }

    // ------------------------------------------------------------------ helpers

    /** @param  Builder<static>  $query */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    /**
     * Meetings overlapping a date window — the calendar's only entry point into this model.
     *
     * @param  Builder<static>  $query
     */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('starts_at', '<=', $to)
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_at')
                ->where('starts_at', '>=', $from)
                ->orWhere('ends_at', '>=', $from));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        // Title and number only — never `minutes`. Matching on minutes text would make the search
        // box report the contents of a record whose most sensitive field is withheld from most of
        // the people who can list it (§61).
        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('number', 'like', $like));
    }

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->title);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isCancelled(): bool
    {
        return $this->statusKey() === 'cancelled';
    }

    public function isPast(): bool
    {
        return ($this->ends_at ?? $this->starts_at)?->isPast() ?? false;
    }

    public function durationMinutes(): ?int
    {
        return $this->ends_at === null
            ? null
            : (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Attendance as a "5 of 9" figure, or null when the register was never taken. */
    public function attendanceSummary(): ?string
    {
        $recorded = $this->participants->whereNotNull('attended');

        if ($recorded->isEmpty()) {
            return null;
        }

        return $recorded->where('attended', true)->count().' of '.$this->participants->count();
    }
}
