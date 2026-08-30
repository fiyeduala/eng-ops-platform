<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\ActorProfile;
use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A notice on the company board (§18).
 *
 * The one model in the system where `org_unit_id` and `project_id` are NOT access control. They are
 * the AUDIENCE: they decide whose dashboard the notice appears on, nothing more. An announcement
 * has no confidentiality column at all, because a notice that must be kept from some of the people
 * who can see the board is a memo that has been filed in the wrong module.
 *
 * That distinction produces two different queries, and mixing them up is the mistake this docblock
 * exists to prevent:
 *
 *   visibleTo($user, 'announcement.view')  — MAY this person read it. Authorization. Every
 *                                            controller action, export and search uses this.
 *   addressedTo($user)                     — IS it meant for this person. Routing. The dashboard
 *                                            and the notification audience use this, ON TOP of
 *                                            visibleTo, never instead of it.
 *
 * Narrowing by audience is not a security control and is not claimed to be one: BASELINE grants
 * `announcement.view: organization`, so anybody in the tenant who goes looking at the archive can
 * read a notice addressed to another department. That is the intended behaviour of a noticeboard.
 *
 * `published_at` is the draft curtain and works exactly as a memo's `issued_at` — see
 * Scopeable::accessPublicationColumn().
 */
class Announcement extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_on' => 'date',
            'is_pinned' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessPublicationColumn(): ?string
    {
        return 'published_at';
    }

    public function accessPublicationAbility(): ?string
    {
        return 'announcement.publish';
    }

    // ------------------------------------------------------------------ audience routing

    /**
     * Notices aimed at this person: company-wide, or at a unit they sit inside, or at a project
     * they are currently on.
     *
     * Note the direction. Authorization reach runs DOWN the tree — a department head sees the
     * teams beneath them. Audience runs UP — somebody in a team three levels inside Engineering is
     * addressed by a notice posted to Engineering. Using the wrong one here would post every
     * department's notices to every manager's dashboard.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAddressedTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $profile = app(ActorProfile::class);
        $unitIds = $profile->ancestorOrgUnitIds($user);
        $projectIds = $profile->projectIds($user);

        return $query->where(function (Builder $group) use ($unitIds, $projectIds) {
            // Addressed to nobody in particular means addressed to everybody.
            $group->where(fn (Builder $q) => $q
                ->whereNull('org_unit_id')
                ->whereNull('project_id'));

            if ($unitIds !== []) {
                $group->orWhereIn('org_unit_id', $unitIds);
            }

            if ($projectIds !== []) {
                $group->orWhereIn('project_id', $projectIds);
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    /** On the board right now: published, and not past its own expiry date. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_on')
                ->orWhere('expires_on', '>=', now()->toDateString()));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('number', 'like', $like));
    }

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->title);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(now()->startOfDay());
    }

    /** Who it is aimed at, in words, for the notice header. */
    public function audienceLabel(): string
    {
        if ($this->project_id !== null) {
            return $this->project?->displayName() ?? 'Project';
        }

        if ($this->org_unit_id !== null) {
            return $this->orgUnit?->name ?? 'Department';
        }

        return 'Everyone';
    }
}
