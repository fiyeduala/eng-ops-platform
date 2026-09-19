<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Five minutes before the shift starts.
 *
 * THE ATTENDANCE LIST IS THE RECORD, not the talk. The content is a paragraph anybody could
 * reproduce; what an inspector or a court asks for is who was standing there, which is why attendees
 * are a table and not a count. `signed_at` on each row is the digital version of the sheet everybody
 * signs on the bonnet of a truck.
 *
 * THE HANDOUT LIVES IN DOCUMENT CONTROL. `document_id` points at it; this table stores no files, for
 * the same reason the drawing register stores none — one place a controlled file can live, one set
 * of rules deciding who may open it (§67).
 *
 * PRESENTER IS A USER OR A NAME. Toolbox talks are routinely given by a subcontractor's foreman who
 * has no login, and a register that could only record employees would record the wrong presenter or
 * nothing at all.
 */
class ToolboxTalk extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'held_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function attendees(): HasMany
    {
        return $this->hasMany(ToolboxTalkAttendee::class)->orderBy('person_name');
    }

    public function presenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presented_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['presented_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return [];
    }

    // ------------------------------------------------------------------ state

    public function wasHeld(): bool
    {
        return $this->held_at !== null && $this->held_at->isPast();
    }

    public function presenterLabel(): string
    {
        return (string) ($this->presenter?->name ?? $this->presenter_name ?? 'Not recorded');
    }

    /** @param  Builder<static>  $query */
    public function scopeHeldBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('held_at', [$from, $to]);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'topic'];
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }
}
