<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Events\MeetingCancelled;
use App\Events\MeetingInvited;
use App\Events\MeetingMinutesCirculated;
use App\Events\MeetingRescheduled;
use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use Illuminate\Support\Facades\DB;

/**
 * Arranging a meeting, holding it, recording it, and turning it into work (§19).
 *
 * The only service in this group with four distinct acts rather than two, and the separation is
 * the point (see MeetingPolicy): arranging, recording, circulating and acting are four different
 * authorities held by four different people in most organizations.
 *
 * Two structural decisions:
 *
 *   CIRCULATION IS IDENTIFIED BY THE TRANSITION'S PERMISSION, NOT BY THE STATUS KEY. A meeting has
 *   two terminal states — minuted and cancelled — and stamping the wrong column would either
 *   publish the minutes of a meeting that never happened or silently swallow a cancellation. The
 *   discriminator is `permission_key === 'meeting.circulate'` on the whitelist row being used. A
 *   tenant may rename Minuted to Closed, reorder the graph, or add an approval step in between;
 *   what they cannot do is invent a permission, because PermissionCatalogue is application code.
 *
 *   ACTION ITEMS ARE TASKS. raiseAction() delegates to TaskService and stamps `linked_type` /
 *   `linked_id` here rather than accepting them from a request. An action arising from a meeting
 *   is work: it belongs on the assignee's list, in their notifications, on the calendar and in the
 *   overdue report, all of which already exist and none of which would look inside a private
 *   meeting_actions table (§90).
 */
class MeetingService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly ActorProfile $profile,
        private readonly TaskService $tasks,
    ) {}

    // ------------------------------------------------------------------ the meeting

    /**
     * @param  array<string,mixed>  $attributes  already validated
     */
    public function create(array $attributes, User $actor): Meeting
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $meeting = new Meeting($attributes);

            $meeting->organization_id = $actor->organization_id;
            $meeting->created_by = $actor->id;
            $meeting->number = $this->numbers->next('meeting');
            $meeting->status_id ??= $this->statuses->initialFor('meeting')?->id;
            $meeting->confidentiality ??= Confidentiality::Internal;

            // Whoever called it, unless they named somebody else. The organizer is an owner column,
            // so this decides who reaches the record without any other grant.
            $meeting->organizer_id ??= $actor->id;

            // Minutes are never created with the meeting. Recording them is a separate act with a
            // separate permission, and accepting them here would route around it.
            $meeting->minutes = null;
            $meeting->minutes_recorded_by = null;
            $meeting->minutes_recorded_at = null;
            $meeting->minutes_circulated_at = null;

            $meeting->save();

            $this->activity->record(
                $meeting,
                'created',
                sprintf('%s scheduled %s', $actor->name, $meeting->displayName()),
                [
                    'number' => $meeting->number,
                    'starts_at' => $meeting->starts_at?->toDateTimeString(),
                ],
                $actor,
            );

            return $meeting->refresh();
        });
    }

    /**
     * Change the arrangements.
     *
     * A move in time or place is a RESCHEDULE and fires its own event, because the people who put
     * it in their diary have to be told — and told what it moved from, which is why the previous
     * values are captured before the fill (§19). Editing the agenda is not a reschedule and
     * deliberately notifies nobody.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Meeting $meeting, array $attributes, User $actor): Meeting
    {
        unset(
            $attributes['number'],
            $attributes['organization_id'],
            $attributes['created_by'],
            $attributes['status_id'],
            $attributes['minutes'],
            $attributes['minutes_recorded_by'],
            $attributes['minutes_recorded_at'],
            $attributes['minutes_circulated_at'],
            $attributes['cancelled_reason'],
        );

        return DB::transaction(function () use ($meeting, $attributes, $actor) {
            $previousStartsAt = $meeting->starts_at?->toDateTimeString();
            $previousLocation = $meeting->location;

            $meeting->fill($attributes);

            $changed = array_keys($meeting->getDirty());

            if ($changed === []) {
                return $meeting;
            }

            $moved = array_intersect($changed, ['starts_at', 'ends_at', 'location', 'meeting_url']) !== [];

            $meeting->save();

            $this->activity->record(
                $meeting,
                $moved ? 'rescheduled' : 'updated',
                $moved
                    ? sprintf('%s moved %s', $actor->name, $meeting->displayName())
                    : sprintf('%s updated %s', $actor->name, $meeting->displayName()),
                array_filter([
                    'fields' => $changed,
                    'from' => $moved ? $previousStartsAt : null,
                    'to' => $moved ? $meeting->starts_at?->toDateTimeString() : null,
                ], fn ($v) => $v !== null),
                $actor,
            );

            $this->profile->flush();
            $meeting->refresh();

            if ($moved && ! $meeting->isCancelled()) {
                MeetingRescheduled::dispatch($meeting, $previousStartsAt, $previousLocation, (int) $actor->id);
            }

            return $meeting;
        });
    }

    /**
     * Move the meeting through the configured whitelist.
     *
     * Circulation and cancellation both land here because both are status moves, and both are told
     * apart by the whitelist row rather than by a status key — see the class docblock.
     *
     * @throws \DomainException when the move is not defined, not permitted, or incomplete
     */
    public function transition(Meeting $meeting, Status $to, User $actor, ?string $comment = null): Meeting
    {
        $row = $this->statuses->find(
            'meeting',
            $meeting->status_id !== null ? (int) $meeting->status_id : null,
            (int) $to->getKey(),
        );

        $isCirculation = $row?->permission_key === 'meeting.circulate';

        if ($isCirculation && ! $meeting->hasMinutes()) {
            throw new \DomainException('There are no minutes to circulate yet.');
        }

        return DB::transaction(function () use ($meeting, $to, $actor, $comment, $isCirculation) {
            $this->statuses->transition($meeting, $to, $actor, $comment);
            $meeting->refresh();

            if ($isCirculation && ! $meeting->minutesCirculated()) {
                $meeting->minutes_circulated_at = now();
                $meeting->saveQuietly();
                $meeting->refresh();

                MeetingMinutesCirculated::dispatch($meeting, (int) $actor->id);

                return $meeting;
            }

            // Terminal and not a circulation: the meeting is off. The reason is kept because
            // "cancelled" without one is the single most-asked question about a cancelled meeting.
            if ($to->is_terminal && ! $isCirculation) {
                $reason = $comment !== null && trim($comment) !== ''
                    ? mb_substr(trim($comment), 0, 500)
                    : 'Cancelled by '.$actor->name;

                if ($meeting->cancelled_reason === null) {
                    $meeting->cancelled_reason = $reason;
                    $meeting->saveQuietly();
                    $meeting->refresh();
                }

                MeetingCancelled::dispatch($meeting, $meeting->cancelled_reason, (int) $actor->id);
            }

            return $meeting;
        });
    }

    // ------------------------------------------------------------------ people

    /**
     * Invite people.
     *
     * The caller has cleared every id through MeetingPolicy::invite() with that exact candidate.
     * Only the newly added are returned and only they are announced — re-inviting the room every
     * time one more person is added is how meeting mail becomes unreadable.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,int>
     */
    public function invite(Meeting $meeting, array $userIds, User $actor, string $role = 'participant'): array
    {
        $role = in_array($role, self::roles(), true) ? $role : 'participant';

        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $existing = $meeting->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $new = array_values(array_diff($userIds, $existing));

        if ($new === []) {
            return [];
        }

        DB::transaction(function () use ($meeting, $new, $actor, $role) {
            foreach ($new as $userId) {
                MeetingParticipant::query()->create([
                    'organization_id' => $meeting->organization_id,
                    'meeting_id' => $meeting->getKey(),
                    'user_id' => $userId,
                    'role' => $role,
                    'created_by' => $actor->id,
                ]);
            }

            $this->activity->record(
                $meeting,
                'invited',
                sprintf('%s invited %d person(s) to %s', $actor->name, count($new), $meeting->displayName()),
                ['user_ids' => $new, 'role' => $role],
                $actor,
            );

            // Being invited reaches the meeting; the memoized reach is now stale.
            $this->profile->flush();
        });

        $meeting->refresh();

        MeetingInvited::dispatch($meeting, $new, (int) $actor->id);

        return $new;
    }

    public function uninvite(Meeting $meeting, int $userId, User $actor): void
    {
        DB::transaction(function () use ($meeting, $userId, $actor) {
            $removed = $meeting->participants()->where('user_id', $userId)->delete();

            if ($removed === 0) {
                return;
            }

            $this->activity->record(
                $meeting,
                'uninvited',
                sprintf('%s removed a participant from %s', $actor->name, $meeting->displayName()),
                ['user_id' => $userId],
                $actor,
            );

            $this->profile->flush();
        });
    }

    /**
     * Somebody sends apologies for their own invitation.
     *
     * Sets `attended` to false rather than leaving it null, because an apology IS a statement about
     * attendance — the null state means "nobody has said", and an apology has said.
     */
    public function respond(MeetingParticipant $participant, ?string $apologies, User $actor): void
    {
        $participant->attended = false;
        $participant->apologies = $apologies !== null ? mb_substr(trim($apologies), 0, 300) : null;
        $participant->save();

        $meeting = $participant->meeting;

        if ($meeting !== null) {
            $this->activity->record(
                $meeting,
                'apologies',
                sprintf('%s sent apologies for %s', $actor->name, $meeting->displayName()),
                [],
                $actor,
            );
        }
    }

    /**
     * Take the register.
     *
     * @param  array<int,bool>  $attendance  participant id => present
     */
    public function recordAttendance(Meeting $meeting, array $attendance, User $actor): void
    {
        if ($attendance === []) {
            return;
        }

        DB::transaction(function () use ($meeting, $attendance, $actor) {
            // Loaded through the meeting's own relation, so a participant id belonging to another
            // meeting — or another tenant — simply is not found (§84).
            $participants = $meeting->participants()
                ->whereKey(array_keys($attendance))
                ->get();

            foreach ($participants as $participant) {
                $participant->attended = (bool) $attendance[$participant->getKey()];
                $participant->save();
            }

            $this->activity->record(
                $meeting,
                'attendance_recorded',
                sprintf('%s recorded attendance for %s', $actor->name, $meeting->displayName()),
                ['present' => $participants->where('attended', true)->count(), 'of' => $participants->count()],
                $actor,
            );
        });
    }

    // ------------------------------------------------------------------ minutes and decisions

    /**
     * Write or amend the minutes. Does not move the meeting's status and does not notify anybody:
     * recording is not publishing, and MeetingPolicy has already refused this after circulation.
     */
    public function recordMinutes(Meeting $meeting, ?string $minutes, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $minutes, $actor) {
            $first = ! $meeting->hasMinutes();

            $meeting->minutes = $minutes;
            $meeting->minutes_recorded_by = $actor->id;
            $meeting->minutes_recorded_at = now();
            $meeting->save();

            $this->activity->record(
                $meeting,
                $first ? 'minutes_recorded' : 'minutes_amended',
                sprintf(
                    '%s %s the minutes of %s',
                    $actor->name,
                    $first ? 'recorded' : 'amended',
                    $meeting->displayName(),
                ),
                [],
                $actor,
            );

            return $meeting->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function addDecision(Meeting $meeting, array $attributes, User $actor): MeetingDecision
    {
        return DB::transaction(function () use ($meeting, $attributes, $actor) {
            $decision = new MeetingDecision($attributes);

            $decision->organization_id = $meeting->organization_id;
            $decision->meeting_id = $meeting->getKey();
            $decision->created_by = $actor->id;
            $decision->sequence = (int) $meeting->decisions()->max('sequence') + 1;

            $decision->save();

            $this->activity->record(
                $meeting,
                'decision_recorded',
                sprintf('%s recorded decision %s', $actor->name, $decision->reference()),
                ['sequence' => $decision->sequence],
                $actor,
            );

            return $decision->refresh();
        });
    }

    /**
     * Work arising.
     *
     * The meeting link is stamped here, never accepted from the request: `linked_type` is a morph,
     * and a route that took a class name from a request body would make every model in the
     * application reachable by name from one endpoint (see the Follower docblock for the same
     * reasoning). The task inherits the meeting's project so it lands on the right timeline.
     *
     * @param  array<string,mixed>  $attributes  already validated task attributes
     */
    public function raiseAction(Meeting $meeting, array $attributes, User $actor): Task
    {
        $attributes['linked_type'] = $meeting->getMorphClass();
        $attributes['linked_id'] = $meeting->getKey();
        $attributes['project_id'] ??= $meeting->project_id;
        $attributes['org_unit_id'] ??= $meeting->org_unit_id;

        $task = $this->tasks->create($attributes, $actor);

        $this->activity->record(
            $meeting,
            'action_raised',
            sprintf('%s raised %s from %s', $actor->name, $task->displayName(), $meeting->displayName()),
            ['task_id' => $task->getKey()],
            $actor,
        );

        return $task;
    }

    /** @return array<int,string> */
    public static function roles(): array
    {
        return array_keys(self::roleOptions());
    }

    /**
     * The same closed list as a value => label map, for the invitation control.
     *
     * The rule reads the keys and the picker reads the pairs, so the two can never drift apart.
     * These are seating roles, not permissions: a chair holds no authority the access engine
     * recognises, and `meeting.circulate` is what actually decides who releases the record.
     *
     * @return array<string,string>
     */
    public static function roleOptions(): array
    {
        return [
            'chair' => 'Chair',
            'secretary' => 'Secretary',
            'participant' => 'Participant',
            'optional' => 'Optional',
        ];
    }
}
