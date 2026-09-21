<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A round of reviews: the period being assessed, and the window in which the company does it.
 *
 * THE SCALE IS COMPANY DATA (§47). `rating_min` and `rating_max` are columns because a five-point
 * scale is an opinion, and a platform that hard-codes one cannot be used by the company that runs
 * 1–4 deliberately, to deny people a comfortable middle. Everything the module computes — the
 * weighted overall rating, the validation on a score — reads these two numbers.
 *
 * THE COMPETENCIES ARE A TEMPLATE, AND OPENING A REVIEW COPIES THEM. Exactly the lesson part 3's
 * checklists taught: a cycle edited in December must not rewrite what somebody was assessed against
 * in June, and an appeal is heard against the copy that was actually scored.
 *
 * A CYCLE IS NOT CONFIDENTIAL. "The 2026 annual review runs from January to March" is an
 * announcement, not a secret; what people said in their reviews is the secret, and that lives on
 * PerformanceReview, which is restricted.
 */
class ReviewCycle extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'opens_on' => 'date',
            'closes_on' => 'date',
            'rating_min' => 'decimal:2',
            'rating_max' => 'decimal:2',
        ];
    }

    /** Cycles and the reviews raised in them share one permission module. */
    public static function permissionModule(): string
    {
        return 'performance';
    }

    // ------------------------------------------------------------------ access

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

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ relations

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(ReviewCycleCompetency::class)->orderBy('sequence');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    // ------------------------------------------------------------------ state

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isOpen(): bool
    {
        return $this->statusKey() === 'open';
    }

    public function isClosed(): bool
    {
        return $this->statusKey() === 'closed';
    }

    /** Whether a score is inside the company's own scale. */
    public function allowsScore(float $score): bool
    {
        return $score >= (float) $this->rating_min && $score <= (float) $this->rating_max;
    }

    public function scaleLabel(): string
    {
        return rtrim(rtrim((string) $this->rating_min, '0'), '.')
            .'–'.rtrim(rtrim((string) $this->rating_max, '0'), '.');
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('key', 'open'));
    }

    /**
     * Name and description, which is what this register's index page matches (§61).
     *
     * The description is in scope here and is not in scope for, say, a document: a review cycle's
     * description is a line of administration ("mid-year, engineering only"), not the contents of
     * a record somebody is kept out of.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where($this->qualifyColumn('name'), 'like', $like)
            ->orWhere($this->qualifyColumn('description'), 'like', $like));
    }

    public function displayName(): string
    {
        return (string) $this->name;
    }
}
