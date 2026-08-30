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
 * Something the meeting DECIDED, pulled out of the minutes so it can be found again.
 *
 * A decision is not a task and not a note. It has no assignee and nothing to complete: "the tower
 * crane moves to the north elevation in week 14" is a fact the project is now built on, and two
 * years later somebody needs to find it without reading forty pages of minutes. Work arising from
 * a decision is a Task pointed at the meeting.
 *
 * `owner_id` is who to ask about it afterwards, not who has to do something — those are different
 * people often enough to be worth separate columns.
 *
 * Carries no confidentiality of its own: a decision is part of its meeting and inherits the
 * meeting's reach, which is why the controller only ever loads decisions through an already
 * authorized Meeting rather than by id.
 */
class MeetingDecision extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['owner_id', 'created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['owner_id'];
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

    public function reference(): string
    {
        return ($this->meeting?->number ?? 'MTG').'/D'.$this->sequence;
    }

    public function displayName(): string
    {
        return $this->reference().' — '.str($this->decision)->limit(80)->toString();
    }
}
