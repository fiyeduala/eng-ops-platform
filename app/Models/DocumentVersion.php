<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One revision of a document — the thing the number actually pointed at on a given day.
 *
 * Holds ONE of two payloads, decided by the parent document's kind:
 *
 *   NATIVE → `content_html` (sanitised, rendered, diffed, exported) plus `content_json` (the
 *            editor's own model, stored so structure survives an editor upgrade, and never
 *            rendered or trusted).
 *   FILE   → `file_path` + `file_hash` + `mime_type` on the private disk. Opaque by nature: the
 *            system versions and guards it without pretending to read it.
 *
 * The invariant lives in DocumentVersionService, not in a CHECK constraint, because SQLite and
 * MySQL disagree about those and a rule enforced in one environment but not the other is worse than
 * one enforced in neither. DocumentVersionTest asserts it.
 *
 * NO SoftDeletes, deliberately. A superseded revision is not deleted, it is superseded — that is
 * what `superseded_at` is for. Giving this table a deleted_at column would offer a "remove revision
 * B" affordance that a controlled register must not have (§26).
 *
 * Reach is INHERITED. A version has no unit, no project and no confidentiality of its own; it is
 * part of its document and the controller only ever reaches one through an already-authorized
 * Document. Confidentiality on the version would be a second answer to a question that already has
 * one, and the two would eventually disagree.
 */
class DocumentVersion extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'is_current' => 'boolean',
            'is_draft_series' => 'boolean',
            'revision_index' => 'integer',
            'word_count' => 'integer',
            'file_size' => 'integer',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'derived_from_version_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    public function transmittalItems(): HasMany
    {
        return $this->hasMany(TransmittalItem::class);
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['author_id', 'created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['author_id'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** Versions are governed by `document.*`; there is no separate document_version module. */
    public static function permissionModule(): string
    {
        return 'document';
    }

    // ------------------------------------------------------------------ query scopes

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('revision_index');
    }

    // ------------------------------------------------------------------ helpers

    public function hasFile(): bool
    {
        return filled($this->file_path);
    }

    public function hasContent(): bool
    {
        return filled($this->content_html);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function displayName(): string
    {
        return ($this->document?->number ?? 'Document').' Rev '.$this->revision;
    }

    public function label(): string
    {
        return 'Rev '.$this->revision;
    }

    /** Bytes as a person reads them. Null when this is a native revision with nothing on disk. */
    public function fileSizeForHumans(): ?string
    {
        if ($this->file_size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->file_size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
    }

    /**
     * The bytes a signature is taken over.
     *
     * For a file revision that is the upload's sha256, recorded at deposit. For a native revision
     * it is the hash of the exact HTML that was approved. Either way the point is the same: if
     * somebody later claims a different revision was the one signed, this settles it (§28).
     */
    public function contentHash(): ?string
    {
        if ($this->hasFile()) {
            return $this->file_hash;
        }

        return $this->content_html !== null ? hash('sha256', $this->content_html) : null;
    }
}
