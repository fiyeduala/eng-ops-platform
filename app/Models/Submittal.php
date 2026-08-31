<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A package sent out for review, and the coded verdict that comes back.
 *
 * A SUBMITTAL IS NOT A TRANSMITTAL. A transmittal says "here is what we sent you on the 4th" and is
 * complete the moment it is issued. A submittal is an open question — "do you accept this pump", "is
 * this method statement acceptable" — that stays open until a review code arrives, and is very often
 * answered with "revise and resubmit". That last case is why `resubmittal_of_id` exists: the chain of
 * attempts against one item is the evidence in every schedule dispute that follows, and a register
 * that overwrites the previous attempt destroys exactly the record it was kept for.
 *
 * REVIEW CODES ARE THE CLIENT'S VOCABULARY, NOT OURS. "A / B / C", "1 / 2 / 3 / 4", "Approved /
 * Approved as noted" — they come from the contract, they differ per project, and a project can change
 * client mid-life. So the code is a string against a configurable list rather than an enum, and no
 * behaviour in the application branches on its value.
 *
 * PER-ITEM CODES EXIST BECAUSE CLIENTS USE THEM. Nine items accepted and the tenth rejected is an
 * ordinary Tuesday, and a single code on the parent cannot say it. `SubmittalItem::review_code`
 * carries the detail; the parent's code carries the overall verdict.
 */
class Submittal extends Model
{
    use RegisterRecord;

    /**
     * Starting types, used until a company edits `engineering.submittal_types`.
     *
     * @var array<int,string>
     */
    public const DEFAULT_TYPES = [
        'Material', 'Shop Drawing', 'Method Statement', 'Sample',
        'Vendor Data', 'Test Certificate', 'Inspection and Test Plan', 'Mock-up',
    ];

    /**
     * Starting review codes, used until a company edits `engineering.submittal_review_codes`.
     *
     * The A/B/C/D convention is the most widely recognised, and every one of these labels is
     * wrong for somebody — which is the reason the list is a setting and this is only a default.
     * Note that nothing in the application branches on a code: the reviewer separately chooses
     * the status the submittal moves to, because "Code B" means "approved" on one contract and
     * "resubmit" on the next, and inferring one from the other would be a guess about a contract
     * this platform has never read.
     *
     * @var array<int,string>
     */
    public const DEFAULT_REVIEW_CODES = [
        'A Approved', 'B Approved as Noted', 'C Revise and Resubmit', 'D Rejected', 'E For Information',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'submitted_on' => 'date',
            'required_by' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function items(): HasMany
    {
        return $this->hasMany(SubmittalItem::class)->orderBy('sort')->orderBy('id');
    }

    /** Who it went to. Null-and-named until Phase 5 gives clients and vendors tables. */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The attempt this one replaces. */
    public function resubmittalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmittal_of_id');
    }

    /** Attempts that replaced this one. Normally none or one. */
    public function resubmittals(): HasMany
    {
        return $this->hasMany(self::class, 'resubmittal_of_id');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['submitted_by', 'reviewed_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['type', 'review_code'];
    }

    // ------------------------------------------------------------------ helpers

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /** Overdue means unreviewed past its date — a reviewed submittal is never late. */
    public function isOverdue(): bool
    {
        return ! $this->isReviewed()
            && $this->required_by !== null
            && $this->required_by->isBefore(now()->startOfDay());
    }

    /**
     * Days until the review is due — negative once it is late, null when there is nothing to wait for.
     *
     * Null for a reviewed package as well as for one with no date, because "seven days left" against
     * a package that came back last week is not a countdown, it is noise on a closed record.
     */
    public function daysRemaining(): ?int
    {
        if ($this->required_by === null || $this->isReviewed()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->required_by, false);
    }

    /**
     * How many attempts this item has taken, counting this one.
     *
     * Walks the chain rather than storing a counter, because a counter is a number that goes wrong
     * the first time somebody deletes a middle attempt. Bounded so a chain that somehow loops — a
     * hand-edited database, a restored backup — cannot hang the page rendering it.
     */
    public function attemptNumber(): int
    {
        $count = 1;
        $current = $this;
        $seen = [$this->getKey() => true];

        while ($current->resubmittal_of_id !== null && $count < 50) {
            $previous = $current->resubmittalOf()->first();

            if ($previous === null || isset($seen[$previous->getKey()])) {
                break;
            }

            $seen[$previous->getKey()] = true;
            $current = $previous;
            $count++;
        }

        return $count;
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at');
    }
}
