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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document that has not happened yet (§65, §66).
 *
 * Same substance as a native document version — same editor, same sanitiser, same renderer — and
 * that sameness is the point (§90). A parallel content stack for templates would mean two HTML
 * pipelines, two sanitisers and, inevitably, one of them getting a fix the other did not.
 *
 * PLACEHOLDERS ARE DATA SUBSTITUTION, NOT CODE. TemplateRenderer resolves `{{document.number}}`
 * against a narrow whitelist of readable paths and leaves anything else alone. It never compiles
 * Blade, never calls eval, and never reads a path the whitelist does not name. The `placeholders`
 * column here is an affordance for the editor's insert menu — the security boundary is the
 * renderer's whitelist, and a template screen that could execute what a user typed would be a
 * remote shell with a nice form around it.
 *
 * Organization-wide for reading, like the other configuration models: a template belongs to no
 * department, so a department-scoped author would otherwise open the New Document screen and find
 * no templates at all.
 */
class Template extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    /** What the template produces. `document` seeds a native document; the rest render to PDF. */
    public const KINDS = [
        'document' => 'Document body',
        'letter' => 'Letter',
        'transmittal' => 'Transmittal cover sheet',
        'report' => 'Report',
        'minutes' => 'Meeting minutes',
        'certificate' => 'Certificate',
    ];

    public const PAGE_SIZES = ['A4' => 'A4', 'A3' => 'A3', 'Letter' => 'Letter', 'Legal' => 'Legal'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'placeholders' => 'array',
            'margins' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
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
        return ['template.view'];
    }

    // ------------------------------------------------------------------ query scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Templates offerable for a register: the ones tied to it, plus the untied general ones.
     *
     * The general ones are included deliberately — a company letterhead is not owned by the
     * drawings register but must be reachable from it.
     */
    public function scopeForCategory(Builder $query, ?int $categoryId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('document_category_id')
            ->when($categoryId !== null, fn (Builder $inner) => $inner->orWhere('document_category_id', $categoryId)));
    }

    // ------------------------------------------------------------------ helpers

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * Page margins in millimetres, with sane defaults.
     *
     * Defaults live here rather than in the migration because a null margin set means "we never
     * chose", and the renderer should be free to improve its defaults later without a data
     * migration rewriting every template that simply never expressed an opinion.
     *
     * @return array{top:int,right:int,bottom:int,left:int}
     */
    public function marginSet(): array
    {
        $margins = (array) ($this->margins ?? []);

        return [
            'top' => (int) ($margins['top'] ?? 20),
            'right' => (int) ($margins['right'] ?? 18),
            'bottom' => (int) ($margins['bottom'] ?? 20),
            'left' => (int) ($margins['left'] ?? 18),
        ];
    }

    public function isLandscape(): bool
    {
        return $this->orientation === 'landscape';
    }
}
