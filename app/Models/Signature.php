<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An internal electronic approval signature (§28, docs/09 §5).
 *
 * EXPLICITLY NOT a qualified or advanced electronic signature under eIDAS, ESIGN or any national
 * scheme. It is a recorded, attributable, tamper-evident statement that a named person approved a
 * named revision at a named time, which is what an engineering approval block has always been and
 * what the overwhelming majority of internal document control actually needs. docs/09 §5 carries
 * the scope statement and the signature panel repeats it, because a system that lets people believe
 * they have more legal weight than they do is worse than one that has none.
 *
 * Two columns carry the evidentiary load:
 *
 *   `designation_snapshot` — the job title AT THE MOMENT OF SIGNING. A promotion next year must not
 *   silently rewrite who approved a drawing as what they are now.
 *
 *   `content_hash` — sha256 of the exact bytes signed. If somebody later claims a different
 *   revision was the one approved, this settles it without argument.
 *
 * NO SoftDeletes and no update path. A signature is written once. Withdrawing an approval is a new
 * event, not an edit to the old one.
 */
class Signature extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'provider_reference' => 'array',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function signable(): MorphTo
    {
        return $this->morphTo('signable');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['user_id'];
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

    /** Governed by `document.sign`; reach to a signature comes through what was signed. */
    public static function permissionModule(): string
    {
        return 'document';
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Does the signature still describe the content it was taken over?
     *
     * The point of storing the hash. A false here means the signed revision's bytes are not the
     * bytes on the screen, which in a controlled register is an incident, not a warning.
     */
    public function matches(?string $hash): bool
    {
        if ($this->content_hash === null || $hash === null) {
            return false;
        }

        return hash_equals($this->content_hash, $hash);
    }

    public function signatoryName(): string
    {
        return $this->name_snapshot ?? $this->user?->name ?? 'Unknown signatory';
    }

    public function signatoryTitle(): ?string
    {
        return $this->designation_snapshot;
    }

    public function hasImage(): bool
    {
        return filled($this->signature_image_path);
    }

    public function displayName(): string
    {
        return $this->signatoryName().' — '.$this->action.' '.($this->signed_at?->format('j M Y') ?? '');
    }
}
