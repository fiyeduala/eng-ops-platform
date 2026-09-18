<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Models\Employee;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The visitor book.
 *
 * THIS IS THE ONE REGISTER IN PHASE 6 WITH NO SUBMIT AND NO APPROVAL, and the absence is the design
 * rather than an oversight. Every other register here refuses to approve itself and waits for the
 * workflow engine to call back; this one refuses to route at all. A receptionist has somebody
 * standing at the desk, and there is no version of that job in which she waits on an inbox. The
 * control is `visitor.check_in`, held by the people at the desk — see VisitorPolicy.
 *
 * WHAT THIS SERVICE DOES INSTEAD is hold the state machine, because the moves here are real. A
 * visitor is booked, arrives and leaves; `expected → on_site → departed` is enforced by
 * StatusCatalogue, and every method below refuses its move before it touches anything. The refusals
 * are `DomainException`s with sentences in them, because the person who will read them is at a desk
 * with a queue forming behind somebody.
 *
 * THE TIMESTAMPS AND THE STATUS ARE WRITTEN TOGETHER, ALWAYS. `checked_in_at` and the `on_site`
 * status are two records of one fact, and the roll call reads the timestamps. Nothing outside this
 * service may set either — they are in `protectedAttributes()` — so the two cannot drift apart, and
 * the answer to "who is in the building" cannot quietly become a different answer from "who is shown
 * as on site".
 *
 * ESCORT AND INDUCTION RULES ARE THE COMPANY'S (§47). Whether a contractor needs an escort, whether
 * an identity document is asked for, how long an induction lasts — all settings, all read at the
 * moment of check-in, none of them written into this file as a constant. What IS written into the
 * record is the answer that was given at the time: `induction_valid_until` is stamped on the visit
 * rather than derived later, so tightening the rule next year does not retroactively make somebody
 * uninducted on a day they were let in.
 */
class VisitorService extends RegisterService
{
    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'visitor';
    }

    protected function modelClass(): string
    {
        return Visitor::class;
    }

    /**
     * Everything the gate stamps.
     *
     * A form that could post `checked_in_at` would be a way through the gate without the permission
     * that guards it, and a form that could post `status_id` would be a way onto the roll call
     * without ever arriving (§84).
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'checked_in_at', 'checked_in_by',
            'checked_out_at', 'checked_out_by',
            'auto_closed',
            'refusal_reason', 'cancellation_reason',
            'host_user_id',
        ];
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Visitor $record */
        $this->syncHostUser($record);
        $this->guardBooking($record);
    }

    /**
     * There is no `beforeUpdate` hook on the base class, so the same two rules are applied here.
     *
     * They have to run on edit as well as on create: a booking whose host is cleared afterwards
     * would otherwise keep the old `host_user_id` and stay readable by somebody who is no longer
     * part of it.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Visitor $record */
        $record->fill($this->safeAttributes($attributes));

        $this->syncHostUser($record);
        $this->guardBooking($record);

        return parent::update($record, $attributes, $actor);
    }

    // ------------------------------------------------------------------ the gate

    /**
     * Somebody has arrived.
     *
     * A walk-in reaches here the same way a booking does — created a moment earlier at the desk,
     * then checked in — so there is exactly one path onto the roll call.
     *
     * @param  array<string,mixed>  $attributes  badge, vehicle, escort, induction, party size
     */
    public function checkIn(Visitor $visitor, User $actor, array $attributes = []): Visitor
    {
        if ($visitor->checked_in_at !== null) {
            throw new \DomainException($visitor->full_name.' is already signed in.');
        }

        if ($visitor->hasDeparted()) {
            throw new \DomainException(
                $visitor->full_name.' has already signed out. Book them in again as a new visit — '
                .'a closed visit is not reopened, because the roll call has to stay readable.'
            );
        }

        foreach (['badge_number', 'vehicle_registration', 'escort_employee_id'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $visitor->setAttribute($key, $attributes[$key]);
            }
        }

        // The party size is corrected at the desk when four people turn up for a booking of two, but
        // a BLANK box is not a correction — it is a receptionist who did not touch the field. Writing
        // it through would put a null in a NOT NULL column and, worse, silently drop the booked
        // number that the roll call is going to count.
        if (! blank($attributes['party_size'] ?? null)) {
            $visitor->party_size = (int) $attributes['party_size'];
        }

        $this->applyInduction($visitor, $attributes);
        $this->guardEscort($visitor);
        $this->guardIdDocument($visitor, $attributes);

        return DB::transaction(function () use ($visitor, $actor) {
            $visitor->checked_in_at = now();
            $visitor->checked_in_by = $actor->id;
            $this->moveTo($visitor, 'on_site');
            $visitor->save();

            $this->activity->record(
                $visitor,
                'checked_in',
                $actor->name.' signed in '.$this->label($visitor).'.',
                ['party_size' => $visitor->headcount(), 'badge' => $visitor->badge_number],
                $actor,
            );

            return $visitor;
        });
    }

    /** They have gone. */
    public function checkOut(Visitor $visitor, User $actor): Visitor
    {
        if ($visitor->checked_in_at === null) {
            throw new \DomainException($visitor->full_name.' has not signed in, so there is nothing to sign out.');
        }

        if ($visitor->hasDeparted()) {
            throw new \DomainException($visitor->full_name.' signed out at '.$visitor->checked_out_at->format('H:i').'.');
        }

        return DB::transaction(function () use ($visitor, $actor) {
            $visitor->checked_out_at = now();
            $visitor->checked_out_by = $actor->id;
            $visitor->auto_closed = false;
            $this->moveTo($visitor, 'departed');
            $visitor->save();

            $this->activity->record(
                $visitor,
                'checked_out',
                $actor->name.' signed out '.$this->label($visitor).'.',
                [],
                $actor,
            );

            return $visitor;
        });
    }

    /** Turned away at the gate, with the reason recorded because somebody will ask. */
    public function refuse(Visitor $visitor, User $actor, string $reason): Visitor
    {
        $this->guardNotArrived($visitor, 'refused');

        if (trim($reason) === '') {
            throw new \DomainException('Refusing somebody entry has to carry a reason.');
        }

        return DB::transaction(function () use ($visitor, $actor, $reason) {
            $visitor->refusal_reason = $reason;
            $this->moveTo($visitor, 'refused');
            $visitor->save();

            $this->activity->record(
                $visitor,
                'refused',
                $actor->name.' refused entry to '.$this->label($visitor).'.',
                ['reason' => $reason],
                $actor,
            );

            return $visitor;
        });
    }

    /** The meeting was called off. */
    public function cancel(Visitor $visitor, User $actor, ?string $reason = null): Visitor
    {
        $this->guardNotArrived($visitor, 'cancelled');

        return DB::transaction(function () use ($visitor, $actor, $reason) {
            $visitor->cancellation_reason = $reason;
            $this->moveTo($visitor, 'cancelled');
            $visitor->save();

            $this->activity->record(
                $visitor,
                'cancelled',
                $actor->name.' cancelled the visit of '.$this->label($visitor).'.',
                ['reason' => $reason],
                $actor,
            );

            return $visitor;
        });
    }

    /** They were expected and never came. */
    public function markNoShow(Visitor $visitor, User $actor): Visitor
    {
        $this->guardNotArrived($visitor, 'marked as a no-show');

        return DB::transaction(function () use ($visitor, $actor) {
            $this->moveTo($visitor, 'no_show');
            $visitor->save();

            $this->activity->record(
                $visitor,
                'no_show',
                $actor->name.' recorded that '.$this->label($visitor).' did not arrive.',
                [],
                $actor,
            );

            return $visitor;
        });
    }

    // ------------------------------------------------------------------ the roll call

    /**
     * Everybody in the building, right now.
     *
     * Scoped by the ordinary global scope like every other read, and by the access engine at the
     * controller — a roll call is not an excuse to hand somebody another tenant's visitors (§75).
     *
     * @return Collection<int,Visitor>
     */
    public function onSite(?int $locationId = null, ?int $projectId = null): Collection
    {
        return Visitor::query()
            ->onSite()
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->with(['host:id,first_name,last_name', 'location:id,name', 'escort:id,first_name,last_name'])
            ->orderBy('checked_in_at')
            ->get();
    }

    /** Heads, not rows — a delegation of six arrived on one record. */
    public function headcountOnSite(?int $locationId = null, ?int $projectId = null): int
    {
        return $this->onSite($locationId, $projectId)->sum(fn (Visitor $v) => $v->headcount());
    }

    // ------------------------------------------------------------------ the end-of-day sweep

    /**
     * Sign out everybody the desk forgot.
     *
     * BADGES DO NOT GET HANDED BACK. Without this, the roll call still shows last Tuesday's visitors
     * and within a fortnight nobody believes it — which costs more than the small dishonesty of a
     * departure time the platform assumed. So the assumption is marked: `auto_closed` is true and the
     * actor is nobody, because the platform did this, and attributing it to whoever owns the cron
     * account would put a name on the timeline that did not witness anything.
     *
     * SAFE TO RUN LATE AND SAFE TO RUN TWICE, like every other sweep: it asks for visits checked in
     * before today's cut-off and not yet checked out, so a missed night is caught up rather than
     * skipped, and a second pass the same evening finds nothing.
     *
     * @return int how many visits were closed
     */
    public function closeOutStragglers(?Carbon $asOf = null): int
    {
        if (! $this->settings->bool('visitor.auto_close_enabled', true)) {
            return 0;
        }

        $now = $asOf?->copy() ?? now();
        $cutOff = $this->lastCutOffBefore($now);
        $closed = 0;

        Visitor::query()
            ->onSite()
            ->where('checked_in_at', '<', $cutOff)
            ->orderBy('id')
            ->chunkById(200, function (Collection $visitors) use ($cutOff, &$closed) {
                foreach ($visitors as $visitor) {
                    DB::transaction(function () use ($visitor, $cutOff, &$closed) {
                        $visitor->checked_out_at = $cutOff;
                        $visitor->auto_closed = true;
                        $this->moveTo($visitor, 'departed');
                        $visitor->save();

                        $this->activity->record(
                            $visitor,
                            'auto_closed',
                            $this->label($visitor).' was still shown on site at the end of the day and '
                            .'was signed out automatically. Nobody witnessed this departure.',
                            ['closed_at' => $cutOff->toDateTimeString()],
                            null,
                        );

                        $closed++;
                    });
                }
            });

        return $closed;
    }

    /**
     * The most recent end of day that has actually passed.
     *
     * YESTERDAY'S, WHEN THE CLOCK IS PAST MIDNIGHT AND THE CUT-OFF IS NOT. With a 23:00 cut-off and
     * an hourly sweep, the run at 00:05 is asking about a cut-off that on today's date is still
     * eighteen hours away — and a version of this that only ever looked at today would answer "not
     * yet", every hour, until 23:00 came round again. The people it was meant to sign out would sit
     * on the roll call for another full day, which is precisely the failure the sweep exists to
     * prevent. Stepping back a day when today's cut-off has not arrived is what makes the sweep
     * catch up rather than skip.
     */
    private function lastCutOffBefore(Carbon $now): Carbon
    {
        $at = $this->settings->string('visitor.auto_close_at', '23:00');
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $at, 2)), 2, 0);

        $cutOff = $now->copy()->setTime(max(0, min(23, $hour)), max(0, min(59, $minute)))->startOfMinute();

        return $now->isBefore($cutOff) ? $cutOff->subDay() : $cutOff;
    }

    // ------------------------------------------------------------------ rules

    /**
     * Keep the two host columns in step.
     *
     * The employee id is the domain fact and the user id is what the access engine compares. Writing
     * the second from the first here is the only place either is set, so they cannot drift — see the
     * migration for why both exist.
     */
    private function syncHostUser(Visitor $visitor): void
    {
        $employeeId = $visitor->host_employee_id;

        if ($employeeId === null) {
            $visitor->host_user_id = null;

            return;
        }

        $host = Employee::query()->whereKey($employeeId)->first(['id', 'user_id']);

        // Null when the host has no sign-in, which is ordinary: a storeman receiving a delivery is
        // a perfectly good host and may never have had an account.
        $visitor->host_user_id = $host?->user_id;
    }

    private function guardBooking(Visitor $visitor): void
    {
        if (trim((string) $visitor->full_name) === '') {
            throw new \DomainException('A visit has to say who is coming.');
        }

        if ($this->settings->bool('visitor.require_host', true) && $visitor->host_employee_id === null) {
            throw new \DomainException('Every visit must say who it is here to see.');
        }

        if ($visitor->expected_from !== null
            && $visitor->expected_to !== null
            && $visitor->expected_to->isBefore($visitor->expected_from)) {
            throw new \DomainException('The visit cannot end before it starts.');
        }

        if ((int) ($visitor->party_size ?? 1) < 1) {
            throw new \DomainException('A visit is at least one person.');
        }
    }

    /**
     * Stamp the induction expiry onto the visit.
     *
     * Copied, not derived. See the class docblock.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function applyInduction(Visitor $visitor, array $attributes): void
    {
        if (! array_key_exists('induction_completed', $attributes)) {
            return;
        }

        $completed = (bool) $attributes['induction_completed'];
        $visitor->induction_completed = $completed;

        if (! $completed) {
            $visitor->induction_completed_on = null;
            $visitor->induction_valid_until = null;

            return;
        }

        $on = ! blank($attributes['induction_completed_on'] ?? null)
            ? Carbon::parse((string) $attributes['induction_completed_on'])
            : now();

        $visitor->induction_completed_on = $on->toDateString();

        $days = $this->settings->int('visitor.induction_validity_days', 365);
        // Zero means it never lapses, which is a real answer for a company that inducts once.
        $visitor->induction_valid_until = $days > 0 ? $on->copy()->addDays($days)->toDateString() : null;
    }

    private function guardEscort(Visitor $visitor): void
    {
        $contractor = strtolower((string) $visitor->purpose_type) === 'contractor';

        if ($contractor && $this->settings->bool('visitor.contractor_requires_escort', true)) {
            $visitor->requires_escort = true;
        }

        if ($visitor->requires_escort && $visitor->escort_employee_id === null) {
            throw new \DomainException(
                $visitor->full_name.' has to be escorted, so the visit needs somebody named to escort them '
                .'before they can be signed in.'
            );
        }
    }

    /** @param array<string,mixed> $attributes */
    private function guardIdDocument(Visitor $visitor, array $attributes): void
    {
        foreach (['id_document_type', 'id_document_number'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $visitor->setAttribute($key, $attributes[$key]);
            }
        }

        if ($this->settings->bool('visitor.capture_id_document', false)
            && blank($visitor->id_document_number)) {
            throw new \DomainException('This site records an identity document for every visitor.');
        }
    }

    /**
     * Three of the terminal statuses mean "this visit never happened", so none of them is reachable
     * once somebody has walked through the door. The sentence names what was attempted, because a
     * receptionist who gets "not allowed" learns nothing from it.
     */
    private function guardNotArrived(Visitor $visitor, string $attempt): void
    {
        if ($visitor->checked_in_at !== null) {
            throw new \DomainException(
                $visitor->full_name.' has already been signed in, so the visit cannot be '
                .$attempt.'. Sign them out instead.'
            );
        }
    }
}
