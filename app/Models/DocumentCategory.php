<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A register within the document control system — not a folder (§72).
 *
 * "Drawings", "Procedures", "Client Correspondence" are not places documents are filed; they are
 * sets of RULES documents obey. How they are numbered, how revisions run, what may be uploaded,
 * how sensitive they are by default, and which approval route they take. Every one of those is a
 * column here, which is what lets one tenant run a strict ISO drawing register beside a casual
 * internal register without a developer touching anything.
 *
 * Categories are configuration, so their access hooks say so: no unit, no project, and
 * `document_category.view` reaches every row inside the tenant. A department-scoped user who could
 * only see "their" categories would be shown a create form with an empty register list — narrower
 * scope on reference data selects nothing rather than a subset, which is exactly what
 * accessOrganizationWideAbilities() exists to prevent. The write ability is NOT listed: reaching
 * every row to read is harmless, reaching every row to edit is not.
 */
class DocumentCategory extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed_extensions' => 'array',
            'default_confidentiality' => Confidentiality::class,
            'default_kind' => DocumentKind::class,
            'is_controlled' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
            'max_upload_mb' => 'integer',
            'retention_months' => 'integer',
            'sort' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOrganizationWideAbilities(): array
    {
        return ['document_category.view'];
    }

    // ------------------------------------------------------------------ helpers

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * Extensions permitted here, or null meaning "the organization setting decides".
     *
     * Null is deliberately NOT "anything". FileVault treats a null as absent and falls back to the
     * tenant-wide whitelist; a category can only ever narrow, never widen (§67).
     *
     * @return array<int,string>|null
     */
    public function uploadExtensions(): ?array
    {
        $list = $this->allowed_extensions;

        if (! is_array($list) || $list === []) {
            return null;
        }

        return array_values(array_map(
            fn ($extension) => strtolower(ltrim((string) $extension, '.')),
            $list,
        ));
    }

    /** Does this register run a draft series (P01, P02) before the issued series? */
    public function hasDraftSeries(): bool
    {
        return filled($this->draft_revision_prefix);
    }

    public function defaultKind(): DocumentKind
    {
        return $this->default_kind ?? DocumentKind::Native;
    }

    public function defaultConfidentiality(): Confidentiality
    {
        return $this->default_confidentiality ?? Confidentiality::Internal;
    }
}
