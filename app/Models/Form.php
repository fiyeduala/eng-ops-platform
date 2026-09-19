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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A form a company built for itself — the identity that outlives its editions (§73).
 *
 * THIS ROW HOLDS ALMOST NOTHING, deliberately. The name, the key and whether it is switched on: the
 * questions all live on FormVersion. That is what lets "the scaffold inspection form" be one thing
 * in a menu, in a report and in a permission grant, while the questions it asked in March and the
 * questions it asks now are different and both still true.
 *
 * Organization-wide for reading, like the other configuration models: a form belongs to no
 * department, and a department-scoped author who could not see it would open the form list and
 * find it empty.
 */
class Form extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_approval' => 'boolean',
        ];
    }

    /** Forms and the submissions made against them share one permission module. */
    public static function permissionModule(): string
    {
        return 'form';
    }

    // ------------------------------------------------------------------ relationships

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The approval route submissions on this form enter, when the company nominated one.
     *
     * Null means "no particular route", which is not the same as "no approval" — that is
     * `requires_approval`. A form that requires approval and nominates nothing falls through to the
     * tenant's default route for form submissions, exactly as a document whose category names no
     * workflow does. See the migration.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * The edition people filling this form in are served.
     *
     * A relation rather than a stored pointer, because at most one version is `published` at a time
     * — publishing retires the one before it, in the same transaction. See the migration for why a
     * pointer column would have been a second source of truth.
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(FormVersion::class)
            ->where('state', FormVersion::STATE_PUBLISHED);
    }

    /**
     * The draft being worked on, if somebody is working on one.
     *
     * At most one, and FormDefinitionService is what keeps that true: opening a draft on a form
     * that already has one hands back the existing draft rather than starting a second. Two open
     * drafts would mean two people editing the same form in different directions, and whichever
     * published last would silently discard the other's work.
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(FormVersion::class)
            ->where('state', FormVersion::STATE_DRAFT);
    }

    // ------------------------------------------------------------------ state

    /**
     * Whether anybody can actually fill this in.
     *
     * Both halves matter: a form switched off is not offered, and a form that has never been
     * published has nothing to offer. The second is the one that catches people out — they build a
     * form, do not press Publish, and wonder why it is not in the list.
     */
    public function isSubmittable(): bool
    {
        return $this->is_active && $this->versions->contains(
            fn (FormVersion $version) => $version->isPublished()
        );
    }

    public function displayName(): string
    {
        return (string) $this->name;
    }

    // ------------------------------------------------------------------ queries

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        // Name and key only. A form's description is prose somebody wrote, and a search that
        // matched it would report through the result count whether a phrase appears in a form the
        // reader may list but has not opened (§61).
        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('key', 'like', $like));
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

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * Reading a form DEFINITION is company-wide; reading a SUBMISSION is not.
     *
     * The same split custom fields make, for the same reason: the questions have to render for
     * everybody who may fill the form in, while what somebody wrote into it is governed entirely by
     * the submission — which carries its own project and department axes (§6).
     *
     * @return array<int,string>
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['form.view'];
    }
}
