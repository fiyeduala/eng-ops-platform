<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ExpiryWatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A date a company asked to be warned about is close, or has passed.
 *
 * ONE EVENT FOR EVERY REGISTER, which is the point of the engine: a permit, a training certificate,
 * a vendor prequalification and a warranty all reach the same listener, so the wording, the
 * audience rules and the §68 gates are written once instead of once per module by whoever
 * remembered.
 *
 * A WARNING IS NOT A STATUS. Nothing here marks anything expired. Expiry is decided by comparing a
 * date to today, every time anybody asks, so a scheduler that stops running costs the company a
 * WARNING and can never make the system call a lapsed certificate valid. The query is the truth;
 * the notification is the courtesy.
 *
 * THE WATCH TRAVELS WITH THE EVENT because the listener cannot word the message without it. Which
 * company asked, what they call this watch, and whether they want to keep hearing about it after
 * the date are all configuration rather than properties of the record.
 */
class RecordExpiring
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $record,
        public readonly ExpiryWatch $watch,
        public readonly int $daysRemaining,
    ) {}

    /** True where the date has already gone by. */
    public function hasPassed(): bool
    {
        return $this->daysRemaining < 0;
    }
}
