<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Models\Concerns\HasCustomFields;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An internal memorandum: a directed, recorded communication (§17).
 *
 * The difference between this and an email is the two things an email cannot do — it is addressed
 * through the access engine, so being on the distribution IS reach; and it is a record, so the
 * issuer can answer "who has seen this" and the organization can answer it two years later.
 *
 * Three decisions worth reading:
 *
 *   - `issued_at` IS the draft curtain. Declared through accessPublicationColumn() below, which
 *     makes every compiled query add "published, or mine, or shared with me, or I am one of the
 *     people who issue memos". Nothing about breadth appears in that list on purpose: a director
 *     with organization-wide reach does not read half-written memos.
 *
 *   - Recipients reach the memo through `assigned` scope, not through a bespoke check. That is
 *     what applyAssignedScope() below is for, and it is the same mechanism that puts a task on its
 *     assignee's list. One definition of reach, one compiler (§90).
 *
 *   - Confidentiality still applies ON TOP of being a recipient. A `confidential` memo addressed to
 *     somebody in another department would otherwise be invisible to the very person it was
 *     written for, so MemoService writes an explicit ACL row for each recipient when it issues one.
 *     That is the record-ACL engine doing the job it exists for rather than this model growing a
 *     special case — see MemoService::issue().
 */
class Memo extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasCustomFields, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'issued_at' => 'datetime',
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

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MemoRecipient::class);
    }

    // ------------------------------------------------------------------ access hooks

    /** "Mine" is a memo I wrote. Being sent one is `assigned`, one rung wider. */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /**
     * Being on the distribution list reaches the memo.
     *
     * whereExists rather than a join: this runs inside an OR group alongside three or four other
     * scope predicates, and a join would silently multiply the rows of every one of them.
     *
     * @param  Builder<static>  $query
     */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        $query->orWhereExists(function ($sub) use ($user) {
            $sub->selectRaw('1')
                ->from('memo_recipients')
                ->whereColumn('memo_recipients.memo_id', $this->qualifyColumn($this->getKeyName()))
                ->where('memo_recipients.user_id', $user->id);
        });
    }

    public function accessPublicationColumn(): ?string
    {
        return 'issued_at';
    }

    public function accessPublicationAbility(): ?string
    {
        return 'memo.issue';
    }

    // ------------------------------------------------------------------ helpers

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotNull('issued_at');
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->whereNull('issued_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        // Subject and number only. The BODY is deliberately not searched: a memo body is the most
        // sensitive free text in the module, and matching on it turns the search box into an
        // oracle that reports whether a phrase appears in a document — including, through the
        // result count alone, ones the reader is allowed to list but has not opened (§61).
        return $query->where(fn (Builder $q) => $q
            ->where('subject', 'like', $like)
            ->orWhere('number', 'like', $like));
    }

    public function displayName(): string
    {
        return trim(($this->number ? $this->number.' — ' : '').$this->subject);
    }

    public function statusKey(): ?string
    {
        return $this->status?->key;
    }

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->issued_at === null;
    }

    /**
     * This person's own row on the distribution list, or null if they are not on it.
     *
     * Returns the ROW rather than a boolean because the caller that matters — the memo page, which
     * stamps the read receipt on open — needs something to write to. Reads from the loaded relation
     * when there is one so the page does not fire a query per call, and falls back to a targeted
     * lookup rather than lazy-loading the whole list.
     */
    public function receiptFor(User $user): ?MemoRecipient
    {
        if ($this->relationLoaded('recipients')) {
            return $this->recipients->firstWhere('user_id', $user->getKey());
        }

        return $this->recipients()->where('user_id', $user->getKey())->first();
    }

    /** Has this particular person opened it? Used for the "read by" summary on the memo page. */
    public function readBy(User $user): bool
    {
        return $this->receiptFor($user)?->read_at !== null;
    }
}
