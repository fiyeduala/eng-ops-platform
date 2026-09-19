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
 * One frozen edition of a form — the questions as they stood.
 *
 * A PUBLISHED VERSION IS IMMUTABLE. Not "should not be edited": there is no path that edits one.
 * FormDefinitionService refuses every write to a published version and opens a new draft instead,
 * because a submission is a statement that somebody answered THESE questions, and a question that
 * can be rewritten afterwards makes every answer to it unfalsifiable.
 *
 * The three states and what each one means to a person:
 *
 *   draft     — being built. Editable. Nobody can submit against it. There is at most one per form,
 *               because two people editing two drafts of the same form would each publish over the
 *               other and only the loser would find out.
 *   published — the live edition. Exactly one per form at a time. Frozen.
 *   retired   — was live, has been superseded. Frozen, not offered, and its submissions stay
 *               readable forever. NOT deleted: deleting it would strand the evidence.
 */
class FormVersion extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    public const STATE_RETIRED = 'retired';

    /** @var array<string,string> */
    public const STATES = [
        self::STATE_DRAFT => 'Draft',
        self::STATE_PUBLISHED => 'Published',
        self::STATE_RETIRED => 'Retired',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'form';
    }

    // ------------------------------------------------------------------ relationships

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FormSection::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Every question in this edition, including repeating-table columns.
     *
     * Ordered by sort then id so that two fields added in the same second — which the builder does
     * when it copies a version — keep a stable order rather than an arbitrary one.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort')->orderBy('id');
    }

    /** The questions people actually answer: top-level only, table columns excluded. */
    public function topLevelFields(): HasMany
    {
        return $this->fields()->whereNull('parent_field_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    // ------------------------------------------------------------------ state

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->state === self::STATE_PUBLISHED;
    }

    public function isRetired(): bool
    {
        return $this->state === self::STATE_RETIRED;
    }

    /**
     * Whether the questions may still be changed.
     *
     * The single question every write on the builder asks. Expressed once, here, so that a new
     * editing path cannot accidentally answer it differently.
     */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    public function displayName(): string
    {
        return ($this->form?->name ?? 'Form').' v'.$this->version;
    }

    // ------------------------------------------------------------------ queries

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('state', self::STATE_DRAFT);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('state', self::STATE_PUBLISHED);
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * A version is reached through its form, and inherits whatever the form grants.
     *
     * Note that AccessScope::Own never inherits through a parent relation — so an author who may
     * see "forms I created" does not thereby see versions of forms somebody else created, which is
     * the correct reading of both grants.
     */
    public function accessParentRelation(): ?string
    {
        return 'form';
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessOrganizationWideAbilities(): array
    {
        return ['form.view'];
    }
}
