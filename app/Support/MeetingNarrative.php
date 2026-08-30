<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Meeting;
use Illuminate\Support\Carbon;

/**
 * The one line that describes a meeting, shared by the four meeting listeners.
 *
 * A helper rather than a method on Meeting, because it is a phrasing decision belonging to the
 * notification layer: the model already exposes the parts, and putting notification copy on an
 * Eloquent model is how a model ends up knowing about email.
 *
 * Nothing here reads the agenda, the minutes or the participant list. Everything it returns is a
 * structured field the recipient is entitled to by virtue of being told about the meeting at all —
 * which matters, because the dispatcher only minimises a body for restricted records and this text
 * has to be safe at every confidentiality level (§68).
 */
final class MeetingNarrative
{
    public static function whenAndWhere(Meeting $meeting): string
    {
        $line = $meeting->starts_at?->format('D j M Y, H:i') ?? 'Time to be confirmed';

        $place = $meeting->location ?: ($meeting->meeting_url !== null ? 'Online' : null);

        return $place !== null ? $line.' — '.$place : $line;
    }

    /** "was Tue 3 Mar 2026, 09:00 — Site office" — the half of a reschedule people actually need. */
    public static function previously(?string $startsAt, ?string $location): ?string
    {
        $parts = array_filter([
            $startsAt !== null ? Carbon::parse($startsAt)->format('D j M Y, H:i') : null,
            $location,
        ]);

        return $parts === [] ? null : 'was '.implode(' — ', $parts);
    }
}
