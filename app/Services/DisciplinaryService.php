<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\Concerns\GrantsRecordAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The most sensitive register in the platform: allegations, investigations, hearings and sanctions.
 *
 * WHO CAN SEE A CASE IS THE WHOLE DESIGN. A disciplinary record is not pinned to the employee's
 * department, unlike everything else about them, because the department head is very often the
 * person the case is ABOUT — and an access rule that reaches down a reporting line would hand them
 * the file on their own conduct. So the record is restricted, `accessParentRelation()` is null, and
 * an empty list for a department head is the intended behaviour rather than a bug to fix later.
 *
 * WHICH LEAVES EXACTLY THREE WAYS IN, all deliberate: HR holds `disciplinary.view_confidential`; the
 * investigator and the person who reported it are assignees on the record; and an investigator
 * assigned to one case gets an explicit Gate 4 allow row for that case alone. The ACL row is how
 * somebody outside HR — a department head investigating an incident in another department, which is
 * normal practice precisely because it is not their own — reads the file they were asked to work on
 * without being able to read anybody else's.
 *
 * AN ACL ROW IS NOT AN ESCALATION. Gate 4 lifts scope and confidentiality; it does not grant the
 * module permission in gate 1. Somebody with no `disciplinary` permission at all still sees nothing,
 * which is why the row can safely be written for every ability on the one record.
 *
 * NOTHING GOES OUT IN A NOTIFICATION. Not the category, not the allegation, not the outcome — §68 in
 * its sharpest form. An email saying "You have been assigned DSC-2026-0004 — gross misconduct,
 * theft" is readable on a lock screen by whoever is standing next to it. People are told there is a
 * case for them to look at, and they sign in to read it.
 *
 * A WARNING EXPIRES. `sanction_expires_on` is what makes the register usable for its most common
 * question — "does this person have a live written warning" — and a platform that answered "yes"
 * forever would make every first offence permanent. Nothing deletes the history; the sanction simply
 * stops counting on the day the company said it would.
 */
class DisciplinaryService extends RegisterService
{
    use GrantsRecordAccess;

    protected function entityType(): string
    {
        return 'disciplinary_case';
    }

    protected function modelClass(): string
    {
        return DisciplinaryCase::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'confidentiality',
            // Every column below is written by an act somebody was authorized to perform. A form
            // that could post `outcome` would be a way to sanction somebody without a hearing (§84).
            'investigator_id', 'investigation_notes', 'hearing_notes',
            'outcome', 'outcome_notes', 'decided_on', 'decided_by', 'sanction_expires_on',
            'appeal_received_on', 'appeal_outcome', 'appeal_notes',
            'closed_at', 'closed_by',
        ];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var DisciplinaryCase $record */
        $record->reported_by = $actor->getKey();
        $record->reported_on ??= CarbonImmutable::today();

        $this->checkShape($record);
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        // The narrative is read by anyone who can read the record, so it names the case and not the
        // allegation. The allegation is in `summary`, behind the same gate as the record itself.
        return $actor->name.' opened '.$record->number.'.';
    }

    /** @param array<string,mixed> $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var DisciplinaryCase $record */
        if ($record->isClosedCase()) {
            throw new \DomainException('This case is closed. Reopening it is a new case.');
        }

        // Who the case is about is settled when it is opened. Moving it to another person would
        // carry the allegation, the investigation and the sanction onto somebody else's file.
        unset($attributes['employee_id']);

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->checkShape($record);

            return parent::update($record, [], $actor);
        });
    }

    /**
     * Put somebody on the case, and give them the access to work it.
     *
     * The previous investigator's grant is withdrawn in the same breath. A case handed on is a case
     * the first investigator no longer reads — leaving the old row would mean every person who ever
     * touched a file keeps it for life, which is the quiet way an access model rots.
     */
    public function assignInvestigator(DisciplinaryCase $case, User $investigator, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase()) {
            throw new \DomainException('This case is closed.');
        }

        if ($investigator->organization_id !== $case->organization_id) {
            throw new \DomainException('That person is not in this organization.');   // §75
        }

        // The subject of a case cannot investigate it, and neither can the person who reported it —
        // the first is obvious and the second is how a grievance becomes a stitch-up.
        if ($case->employee?->user_id === $investigator->getKey()) {
            throw new \DomainException('Somebody cannot investigate a case about themselves.');
        }

        return DB::transaction(function () use ($case, $investigator, $actor) {
            $previous = $case->investigator_id;

            if ($previous !== null && $previous !== $investigator->getKey()) {
                $this->revokeRecordAccess($case, $previous);
            }

            $case->forceFill(['investigator_id' => $investigator->getKey()]);

            if ($case->statusKey() === 'open') {
                $this->moveTo($case, 'investigating');
            }

            $case->save();

            // `'*'` — every ability they already hold, on this one case: read the file, write the
            // findings, attach what they gather. See GrantsRecordAccess for why that is not an
            // escalation.
            $this->grantRecordAccess($case, $investigator, $actor, 'Assigned to investigate this case.');

            $this->activity->record(
                $case,
                'assigned',
                $actor->name.' asked '.$investigator->name.' to investigate '.$case->number.'.',
                ['investigator' => $investigator->getKey()],
                $actor,
            );

            return $case;
        });
    }

    public function recordInvestigation(DisciplinaryCase $case, ?string $notes, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase()) {
            throw new \DomainException('This case is closed.');
        }

        return DB::transaction(function () use ($case, $notes, $actor) {
            $case->forceFill(['investigation_notes' => $notes]);

            if ($case->statusKey() === 'open') {
                $this->moveTo($case, 'investigating');
            }

            $case->save();

            $this->activity->record($case, 'updated', $actor->name.' updated the investigation on '.$case->number.'.', [], $actor);

            return $case;
        });
    }

    public function scheduleHearing(DisciplinaryCase $case, string $on, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase() || $case->isDecided()) {
            throw new \DomainException('This case has already been decided.');
        }

        return DB::transaction(function () use ($case, $on, $actor) {
            $case->forceFill(['hearing_on' => CarbonImmutable::parse($on)]);
            $this->moveTo($case, 'hearing');
            $case->save();

            $this->activity->record(
                $case,
                'scheduled',
                $actor->name.' set a hearing on '.$case->hearing_on->format('j M Y').' for '.$case->number.'.',
                [],
                $actor,
            );

            return $case;
        });
    }

    public function recordHearing(DisciplinaryCase $case, ?string $notes, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase()) {
            throw new \DomainException('This case is closed.');
        }

        return DB::transaction(function () use ($case, $notes, $actor) {
            $case->forceFill(['hearing_notes' => $notes]);
            $case->save();

            $this->activity->record($case, 'updated', $actor->name.' recorded the hearing on '.$case->number.'.', [], $actor);

            return $case;
        });
    }

    /**
     * The outcome.
     *
     * Not terminal on purpose: a decided case stays decided while an appeal runs, and only closing
     * it ends the matter. A status vocabulary where "decided" was the end would leave an appeal
     * nowhere to be recorded, and it would be recorded in an email instead.
     *
     * @param  array<string,mixed>  $attributes  outcome, outcome_notes, sanction_expires_on
     */
    public function decide(DisciplinaryCase $case, array $attributes, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase()) {
            throw new \DomainException('This case is closed.');
        }

        $outcome = trim((string) ($attributes['outcome'] ?? ''));

        if ($outcome === '') {
            throw new \DomainException('Say what the outcome is.');
        }

        return DB::transaction(function () use ($case, $attributes, $outcome, $actor) {
            $expires = filled($attributes['sanction_expires_on'] ?? null)
                ? CarbonImmutable::parse((string) $attributes['sanction_expires_on'])
                : null;

            if ($expires !== null && $expires->lt(CarbonImmutable::today())) {
                throw new \DomainException('A sanction cannot expire before it is given.');
            }

            $case->forceFill([
                'outcome' => $outcome,
                'outcome_notes' => $attributes['outcome_notes'] ?? null,
                'decided_on' => CarbonImmutable::today(),
                'decided_by' => $actor->getKey(),
                'sanction_expires_on' => $expires,
            ]);
            $this->moveTo($case, 'decided');
            $case->save();

            // The outcome IS named here, unlike the allegation: by this point it is a decision the
            // company has taken and the people who can read the record are the people who need it.
            $this->activity->record(
                $case,
                'decided',
                $actor->name.' decided '.$case->number.': '.$case->outcomeLabel().'.',
                ['outcome' => $outcome],
                $actor,
            );

            return $case;
        });
    }

    /** An appeal was lodged. The case goes back to being live. */
    public function recordAppeal(DisciplinaryCase $case, string $receivedOn, User $actor): DisciplinaryCase
    {
        if (! $case->isDecided()) {
            throw new \DomainException('There is nothing to appeal against yet.');
        }

        if ($case->isClosedCase()) {
            throw new \DomainException('This case is closed.');
        }

        return DB::transaction(function () use ($case, $receivedOn, $actor) {
            $case->forceFill(['appeal_received_on' => CarbonImmutable::parse($receivedOn)]);
            $case->save();

            $this->activity->record($case, 'appealed', 'An appeal was received against '.$case->number.'.', [], $actor);

            return $case;
        });
    }

    /**
     * The appeal's answer. May overturn the sanction, which clears the expiry date with it.
     *
     * @param  array<string,mixed>  $attributes  appeal_outcome, appeal_notes, outcome, sanction_expires_on
     */
    public function decideAppeal(DisciplinaryCase $case, array $attributes, User $actor): DisciplinaryCase
    {
        if ($case->appeal_received_on === null) {
            throw new \DomainException('No appeal has been received.');
        }

        $appealOutcome = trim((string) ($attributes['appeal_outcome'] ?? ''));

        if ($appealOutcome === '') {
            throw new \DomainException('Say what the appeal decided.');
        }

        return DB::transaction(function () use ($case, $attributes, $appealOutcome, $actor) {
            $case->forceFill([
                'appeal_outcome' => $appealOutcome,
                'appeal_notes' => $attributes['appeal_notes'] ?? null,
            ]);

            // An appeal that changes the sanction changes the record, not a note beside it: the
            // question "does this person have a live warning" has to read one column and be right.
            if (filled($attributes['outcome'] ?? null)) {
                $case->forceFill([
                    'outcome' => $attributes['outcome'],
                    'sanction_expires_on' => filled($attributes['sanction_expires_on'] ?? null)
                        ? CarbonImmutable::parse((string) $attributes['sanction_expires_on'])
                        : null,
                ]);
            }

            $case->save();

            $this->activity->record($case, 'updated', $actor->name.' recorded the appeal decision on '.$case->number.'.', [], $actor);

            return $case;
        });
    }

    /**
     * Close the case and take the investigator's key back.
     *
     * The grant is withdrawn rather than left to expire, because it was given for a piece of work
     * and the work is over. The case itself stays exactly where it is — nothing is deleted, and the
     * sanction goes on counting until the day it lapses.
     */
    public function close(DisciplinaryCase $case, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase()) {
            throw new \DomainException('This case is already closed.');
        }

        if (! $case->isDecided()) {
            throw new \DomainException('Decide the case before closing it.');
        }

        return DB::transaction(function () use ($case, $actor) {
            $case->forceFill(['closed_at' => now(), 'closed_by' => $actor->getKey()]);
            $this->moveTo($case, 'closed');
            $case->save();

            if ($case->investigator_id !== null) {
                $this->revokeRecordAccess($case, $case->investigator_id);
            }

            $this->activity->record($case, 'closed', $actor->name.' closed '.$case->number.'.', [], $actor);

            return $case;
        });
    }

    /** Withdrawn: the allegation was not pursued. Kept, because withdrawals are evidence too. */
    public function withdraw(DisciplinaryCase $case, string $reason, User $actor): DisciplinaryCase
    {
        if ($case->isClosedCase()) {
            throw new \DomainException('This case has already finished.');
        }

        if (trim($reason) === '') {
            throw new \DomainException('Say why the case is being withdrawn.');
        }

        return DB::transaction(function () use ($case, $reason, $actor) {
            $case->forceFill([
                'outcome_notes' => $reason,
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
            ]);
            $this->moveTo($case, 'withdrawn');
            $case->save();

            if ($case->investigator_id !== null) {
                $this->revokeRecordAccess($case, $case->investigator_id);
            }

            $this->activity->record($case, 'withdrawn', $actor->name.' withdrew '.$case->number.': '.$reason, [], $actor);

            return $case;
        });
    }

    // ================================================================== internals

    private function checkShape(DisciplinaryCase $case): void
    {
        if ($case->employee_id === null) {
            throw new \DomainException('Say who the case concerns.');
        }

        if (blank($case->summary)) {
            throw new \DomainException('Describe what happened.');
        }

        if (! in_array($case->severity, DisciplinaryCase::SEVERITIES, true)) {
            throw new \DomainException('Say how serious this is.');
        }

        if ($case->incident_on !== null && $case->incident_on->isFuture()) {
            throw new \DomainException('The incident date is in the future.');
        }

        if ($case->incident_on !== null && $case->reported_on !== null && $case->reported_on->lt($case->incident_on)) {
            throw new \DomainException('The case cannot be reported before the incident happened.');
        }
    }
}
