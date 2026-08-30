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
 * One person invited to one meeting — invitation list and attendance register in one row.
 *
 * Like MemoRecipient, writing this row is a visibility grant: it reaches the meeting through
 * `meeting.view:assigned`. MeetingPolicy::invite() therefore asks the two-question form.
 *
 * `role` records who chaired and who is expected to take the minutes. It grants nothing — being
 * named secretary does not confer `meeting.record_minutes`, which is a permission like any other.
 * The role is there so the meeting page can say who was supposed to do what.
 */
class MeetingParticipant extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['user_id', 'created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['user_id'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    public function isChair(): bool
    {
        return $this->role === 'chair';
    }

    public function isSecretary(): bool
    {
        return $this->role === 'secretary';
    }

    /** Present, absent, or "not recorded" — three states, not two. */
    public function attendanceLabel(): string
    {
        return match ($this->attended) {
            true => 'Present',
            false => filled($this->apologies) ? 'Apologies' : 'Absent',
            default => 'Not recorded',
        };
    }

    public function displayName(): string
    {
        return ($this->user?->name ?? 'Participant').' ('.$this->role.')';
    }
}
