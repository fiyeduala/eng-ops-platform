<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The covering note for documents leaving the building (§38, docs/09 §6).
 *
 * It exists to answer one question, asked years later and usually by somebody's lawyer: what
 * exactly did we issue to the client on 4 March, and did they confirm receipt?
 *
 * That is why the items pin VERSIONS rather than documents, and why `issued_at` is the publication
 * column. A draft transmittal is a list somebody is still assembling; it must not appear in the
 * register of what has been sent, to anybody, however senior — the same draft curtain a memo gets,
 * through the same machinery.
 *
 * The recipient is a nullable morph plus free text, not a foreign key to a parties table. Clients
 * and vendors arrive in Phase 5, and half of all real transmittals go to somebody who will never be
 * a record here — a regulator, a client's subcontractor, one named engineer at a firm we deal with
 * once. Forcing a user to invent a fake client record before they can send a letter is how document
 * registers fill up with junk.
 */
class Transmittal extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    /**
     * Why it was issued — which is a statement with contractual weight, not decoration. A register
     * that cannot distinguish "issued for construction" from "issued for comment" is useless in the
     * dispute it exists to survive.
     */
    public const PURPOSES = [
        'for_information' => 'For information',
        'for_review' => 'For review and comment',
        'for_approval' => 'For approval',
        'for_construction' => 'For construction',
        'for_tender' => 'For tender',
        'as_built' => 'As-built record',
    ];

    public const TRANSPORTS = [
        'email' => 'Email',
        'portal' => 'Client portal',
        'courier' => 'Courier',
        'hand' => 'By hand',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'requires_acknowledgement' => 'boolean',
            'issued_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'due_response_on' => 'date',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function items(): HasMany
    {
        return $this->hasMany(TransmittalItem::class)->orderBy('sort');
    }

    public function toParty(): MorphTo
    {
        return $this->morphTo('to_party');
    }

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

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['created_by', 'issued_by'];
    }

    public function accessPublicationColumn(): ?string
    {
        return 'issued_at';
    }

    public function accessPublicationAbility(): ?string
    {
        return 'transmittal.issue';
    }

    // ------------------------------------------------------------------ query scopes

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotNull('issued_at');
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->whereNull('issued_at');
    }

    public function scopeAwaitingAcknowledgement(Builder $query): Builder
    {
        return $query->issued()
            ->where('requires_acknowledgement', true)
            ->whereNull('acknowledged_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('number', 'like', $like)
            ->orWhere('subject', 'like', $like)
            ->orWhere('to_name', 'like', $like));
    }

    // ------------------------------------------------------------------ helpers

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->issued_at === null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function awaitingAcknowledgement(): bool
    {
        return $this->isIssued() && $this->requires_acknowledgement && ! $this->isAcknowledged();
    }

    public function isOverdue(): bool
    {
        return $this->awaitingAcknowledgement()
            && $this->due_response_on !== null
            && $this->due_response_on->isPast();
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? ucfirst(str_replace('_', ' ', (string) $this->purpose));
    }

    public function transportLabel(): ?string
    {
        return $this->transport === null ? null : (self::TRANSPORTS[$this->transport] ?? $this->transport);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->subject);
    }
}
