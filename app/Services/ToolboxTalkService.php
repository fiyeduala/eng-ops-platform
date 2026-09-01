<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ToolboxTalk;
use App\Models\ToolboxTalkAttendee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The five minutes before the shift starts.
 *
 * THE ATTENDANCE LIST IS WRITTEN BEFORE THE TALK FREEZES, WHICH IS WHY `record()` TAKES IT. The
 * supervisor delivers the talk, collects the names, and enters both in one act — a workflow where
 * the talk is marked held and then the attendees are typed in afterwards would leave a gap in which
 * the record exists and is already frozen but says nobody attended.
 *
 * MOST ATTENDEES ARE NOT USERS. Subcontractors, agency labour, a client's visitor — they have no
 * account and will never have one, so `person_name` is the required column and `user_id` is the
 * optional one. That is the right way round for site records generally, and Phase 5 pointing
 * `company` at a vendor record will not change it.
 */
class ToolboxTalkService extends RegisterService
{
    protected function entityType(): string
    {
        return 'toolbox_talk';
    }

    protected function modelClass(): string
    {
        return ToolboxTalk::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'held_at', 'presented_by'];
    }

    /**
     * Record that the talk was delivered, and who was there.
     *
     * @param  array<int,array<string,mixed>>  $attendees
     */
    public function record(ToolboxTalk $talk, array $attendees, User $actor, ?string $heldAt = null): ToolboxTalk
    {
        if ($talk->wasHeld()) {
            throw new \DomainException($talk->number.' has already been recorded as held.');
        }

        return DB::transaction(function () use ($talk, $attendees, $actor, $heldAt) {
            foreach (array_values($attendees) as $row) {
                $name = trim((string) ($row['person_name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $attendee = new ToolboxTalkAttendee;
                $attendee->organization_id = $talk->organization_id;
                $attendee->toolbox_talk_id = $talk->getKey();
                $attendee->user_id = $row['user_id'] ?? null;
                $attendee->person_name = $name;
                $attendee->company = $row['company'] ?? null;
                $attendee->trade = $row['trade'] ?? null;
                $attendee->save();
            }

            $talk->forceFill([
                'held_at' => $heldAt ?? now(),
                'presented_by' => $talk->presented_by ?? $actor->getKey(),
            ]);

            $this->moveTo($talk, 'held');
            $talk->save();

            $this->activity->record(
                $talk,
                'held',
                $actor->name.' recorded '.$this->label($talk).' as delivered.',
                ['attendees' => $talk->attendees()->count()],
                $actor,
                $this->projectIdOf($talk),
            );

            return $talk->load('attendees');
        });
    }

    /**
     * An attendee signs for themselves.
     *
     * Nobody signs on anybody's behalf through here: the policy requires the row to be about this
     * user, and this method refuses a second signature. A signature that could be applied twice or
     * by somebody else is not a signature.
     */
    public function sign(ToolboxTalkAttendee $attendee, User $actor): ToolboxTalkAttendee
    {
        if ($attendee->hasSigned()) {
            return $attendee;
        }

        $attendee->forceFill(['signed_at' => now()]);
        $attendee->save();

        return $attendee;
    }
}
