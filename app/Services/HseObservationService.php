<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HseObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "I saw something."
 *
 * ANONYMITY IS ENFORCED HERE, NOT IN THE FORM. `beforeCreate()` sets `observed_by` to null and
 * `created_by` to null when `is_anonymous` is true, so the name is never written rather than being
 * written and hidden. That distinction is the whole promise: a suppressed name is recoverable by
 * anybody with database access and by every future feature that forgets to suppress it, and a
 * register people do not trust twice collects nothing.
 *
 * IT ALSO MEANS THE REPORTER LOSES THE RECORD, which is the honest cost of the promise. They cannot
 * find it under "mine", cannot follow it, and are not told when it is closed. Filing anonymously is
 * letting it go, and pretending otherwise would require keeping exactly the link the anonymity was
 * supposed to break.
 *
 * `created_by` NULL IS UNUSUAL IN THIS APPLICATION AND IS CORRECT HERE. Every other register uses
 * it for ownership and for the audit trail. Here it would be the same name under a different
 * column, and the audit engine's entry names the record and the action without naming a person, so
 * nothing that matters is lost.
 */
class HseObservationService extends RegisterService
{
    protected function entityType(): string
    {
        return 'hse_observation';
    }

    protected function modelClass(): string
    {
        return HseObservation::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'observed_by', 'closed_at', 'closed_by'];
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var HseObservation $record */
        if ($record->observed_at === null) {
            $record->observed_at = now();
        }

        if ($record->is_anonymous) {
            // Both, and in that order. See the class docblock — the name is not stored.
            $record->observed_by = null;
            $record->observer_name = null;
            $record->setAttribute('created_by', null);

            return;
        }

        $record->observed_by = $actor->getKey();
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var HseObservation $record */
        return $record->is_anonymous
            ? 'An observation was reported anonymously: '.$record->number.'.'
            : $actor->name.' reported '.$this->label($record).'.';
    }

    /** Close the observation out. */
    public function close(HseObservation $observation, User $actor, ?string $notes = null): HseObservation
    {
        if ($observation->isClosedOut()) {
            throw new \DomainException($observation->number.' is already closed.');
        }

        return DB::transaction(function () use ($observation, $actor, $notes) {
            $observation->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
            ]);

            $this->moveTo($observation, 'closed');
            $observation->save();

            $this->activity->record(
                $observation,
                'closed',
                $actor->name.' closed '.$this->label($observation).'.',
                array_filter(['notes' => $notes]),
                $actor,
                $this->projectIdOf($observation),
            );

            return $observation;
        });
    }

    public function reopen(HseObservation $observation, User $actor, string $reason): HseObservation
    {
        if (! $observation->isClosedOut()) {
            throw new \DomainException($observation->number.' is not closed.');
        }

        if (blank($reason)) {
            throw new \DomainException('Reopening an observation must say why.');
        }

        return DB::transaction(function () use ($observation, $actor, $reason) {
            $observation->forceFill(['closed_at' => null, 'closed_by' => null]);

            $this->moveTo($observation, 'open');
            $observation->save();

            $this->activity->record(
                $observation,
                'reopened',
                $actor->name.' reopened '.$this->label($observation).'.',
                ['reason' => $reason],
                $actor,
                $this->projectIdOf($observation),
            );

            return $observation;
        });
    }
}
