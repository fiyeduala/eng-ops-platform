<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One date a company asked to be warned about, on one register.
 *
 * A WATCH IS A QUESTION, NOT AN ANSWER. It holds no state about what has been sent, no last-run
 * stamp and no record of which certificates it has already mentioned — see ExpirySweeper for why
 * that asymmetry is the whole design. Everything here is a company's standing instruction, and the
 * sweep re-answers it from the database every morning.
 */
class ExpiryWatch extends Model
{
    use Auditable, BelongsToOrganization, Scopeable;

    /**
     * What a new watch warns on, when nobody says otherwise.
     *
     * Three e-mails: far enough out to renew something, close enough to chase it, and the day
     * before. A company can say anything it likes instead; this is the shape of a reminder people
     * still read by the third one.
     */
    public const DEFAULT_LEAD_DAYS = [30, 7, 1];

    /** The most milestones one watch may carry, so a watch cannot become a mailing list. */
    public const MAX_LEAD_DAYS = 6;

    /** The furthest ahead a watch may look. Two years is already further than anybody plans. */
    public const MAX_LEAD_DAY = 730;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lead_days' => 'array',
            'notify_role_ids' => 'array',
            'notify_user_ids' => 'array',
            'warn_when_overdue' => 'boolean',
            'notify_owner' => 'boolean',
            'notify_followers' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ================================================================== scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    // ================================================================== reading

    /**
     * The milestones, cleaned up, largest first.
     *
     * Sorted and de-duplicated on the way OUT rather than trusted from the column, because a watch
     * written by an earlier version of the validator — or by a seed, or by hand during a support
     * call — should still behave rather than warn twice on the same day.
     *
     * @return array<int,int>
     */
    public function leadDays(): array
    {
        $days = array_map('intval', (array) ($this->lead_days ?? []));
        $days = array_values(array_unique(array_filter($days, fn (int $d) => $d >= 0 && $d <= self::MAX_LEAD_DAY)));

        rsort($days);

        return $days === [] ? self::DEFAULT_LEAD_DAYS : $days;
    }

    /**
     * Does this watch speak today, about a record this many days from its date?
     *
     * Negative means the date has passed. An overdue record is mentioned every day if the watch was
     * told to — that is the uninsured-truck case — and otherwise falls silent once the date goes by,
     * because a quotation that expired last month is history rather than news.
     */
    public function speaksAt(int $daysRemaining): bool
    {
        if ($daysRemaining < 0) {
            return $this->warn_when_overdue;
        }

        return in_array($daysRemaining, $this->leadDays(), true);
    }

    /** The furthest out this watch ever speaks, which is how far the sweep has to look. */
    public function horizonDays(): int
    {
        return max($this->leadDays());
    }

    /**
     * @return array<int,int>
     */
    public function notifyRoleIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->notify_role_ids ?? []))));
    }

    /**
     * @return array<int,int>
     */
    public function notifyUserIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->notify_user_ids ?? []))));
    }

    public function displayName(): string
    {
        return $this->name;
    }
}
