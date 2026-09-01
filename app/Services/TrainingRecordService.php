<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who holds what, and until when.
 *
 * `expires_on` IS COMPUTED ONCE AND THEN STORED. TrainingCourse::expiryFrom() is called here, at
 * creation, and never again. A derived expiry would look tidier and would be wrong: the day
 * somebody shortens a course's validity from five years to three, every certificate ever issued
 * against it would silently move, and a register would start reporting that people were
 * uncertificated on dates when they were not.
 *
 * AN EXPLICIT EXPIRY ALWAYS WINS. Certificates issued by an external body carry the body's own
 * expiry date printed on the card, and that date is the truth regardless of what the course record
 * says. The computation is a convenience for the common case, not an authority.
 *
 * `person_name` IS FILLED FROM THE USER WHEN ONE IS LINKED, and stored rather than joined, for the
 * reason incident_persons stores it: a name captured at the time survives the account being
 * renamed, deactivated or deleted, and a certificate that resolves to "Unknown user #412" at an
 * audit is not a certificate.
 */
class TrainingRecordService extends RegisterService
{
    protected function entityType(): string
    {
        return 'hse_training';
    }

    protected function modelClass(): string
    {
        return TrainingRecord::class;
    }

    /**
     * `expires_on` IS DELIBERATELY NOT PROTECTED.
     *
     * It is not a workflow outcome — it is a date copied off a card, and cards get mistyped. Every
     * other register freezes the columns the process stamps; here the only such column is the
     * status. Somebody who enters 2027 for 2026 must be able to correct it, and the audit trail
     * records that they did.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return ['status_id'];
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var TrainingRecord $record */
        if ($record->user_id !== null && blank($record->person_name)) {
            $holder = $record->user()->first();
            $record->person_name = $holder?->name;
        }

        if (blank($record->person_name)) {
            throw new \DomainException('A training record must name the person who holds the certificate.');
        }

        if (blank($record->expires_on)) {
            $record->expires_on = $this->computedExpiry($record);
        }
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var TrainingRecord $record */
        return $actor->name.' recorded '.$this->label($record)
            .($record->expires_on !== null ? ', valid to '.$record->expires_on->format('j M Y') : '')
            .'.';
    }

    /**
     * Renewing is a NEW RECORD, not an edit.
     *
     * Deliberately, and it is the only sensible arrangement: the old certificate was valid for the
     * period it covered, and overwriting it would erase the evidence that somebody was qualified on
     * a date they were later asked about. The register is meant to accumulate.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function renew(TrainingRecord $previous, array $attributes, User $actor): TrainingRecord
    {
        /** @var TrainingRecord $record */
        $record = $this->create(array_merge([
            'training_course_id' => $previous->training_course_id,
            'user_id' => $previous->user_id,
            'person_name' => $previous->person_name,
            'company' => $previous->company,
            'project_id' => $previous->project_id,
            'org_unit_id' => $previous->org_unit_id,
        ], $attributes), $actor);

        $this->activity->record(
            $previous,
            'renewed',
            $actor->name.' renewed this certificate as '.$record->number.'.',
            ['renewal' => $record->number],
            $actor,
            $this->projectIdOf($previous),
        );

        return $record;
    }

    // ================================================================== internals

    /**
     * The course's validity applied to the completion date — used only when nothing was entered.
     *
     * The card in somebody's wallet outranks the arithmetic, which is why this runs behind a blank
     * check rather than over the top of a supplied date. See the class docblock.
     */
    private function computedExpiry(TrainingRecord $record): ?string
    {
        if ($record->completed_on === null || $record->training_course_id === null) {
            return null;
        }

        $course = TrainingCourse::query()->find($record->training_course_id);

        return $course?->expiryFrom($record->completed_on)?->toDateString();
    }
}
