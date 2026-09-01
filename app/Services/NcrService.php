<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NcrDispositioned;
use App\Events\NcrIssued;
use App\Models\Ncr;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * It does not meet the specification.
 *
 * FIVE STEPS, EACH BY A DIFFERENT PERSON: raise, respond, disposition, verify, close. That is the
 * whole value of the register — a non-conformance the raiser can raise, answer, accept and close
 * unaided is a note to self with a reference number.
 *
 * NOBODY DISPOSITIONS THEIR OWN, enforced here as well as in NcrPolicy. The engineering decision
 * about what happens to non-conforming work must not be made by the person who found it, for the
 * same reason a change request is not approved by its author.
 *
 * REPAIR, USE-AS-IS AND REGRADE REQUIRE NOTES. Rework and reject speak for themselves — the work is
 * being redone or thrown away. The other three leave non-conforming material in the permanent works,
 * and they are the ones somebody will be asked to justify years later while holding a failed
 * component. Ncr::DISPOSITIONS_NEEDING_NOTES holds the list and this service refuses without them.
 *
 * VERIFICATION IS NOT DISPOSITION. Deciding to rework something and confirming that the rework was
 * done and is acceptable are separate acts, usually weeks apart and often by different people. An
 * NCR closed at the moment of disposition would record an intention as though it were an outcome.
 *
 * CORRECTIVE ACTIONS ARE RAISED THROUGH CONFIGURATION, not from here — the same `create_record`
 * workflow verb an incident uses, landing in the same corrective action register, so "what is
 * outstanding against me" has exactly one answer (§90).
 */
class NcrService extends RegisterService
{
    protected function entityType(): string
    {
        return 'ncr';
    }

    protected function modelClass(): string
    {
        return Ncr::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'response', 'responded_at', 'responded_by',
            'disposition', 'disposition_notes', 'dispositioned_at', 'dispositioned_by',
            'verified_at', 'verified_by', 'verification_notes',
            'closed_at', 'closed_by', 'closure_notes',
        ];
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Ncr $record */
        if ($record->detected_by === null) {
            $record->detected_by = $actor->getKey();
        }

        if ($record->detected_on === null) {
            $record->detected_on = now()->toDateString();
        }
    }

    /**
     * Issue it to the other side.
     *
     * Separate from creating it, because an NCR is drafted, checked and then sent — and the date it
     * was sent is the date the response clock starts.
     */
    public function issue(Ncr $ncr, User $actor): Ncr
    {
        if ($ncr->hasResponse() || $ncr->isDispositioned()) {
            throw new \DomainException($ncr->number.' has already gone past issue.');
        }

        return DB::transaction(function () use ($ncr, $actor) {
            $this->moveTo($ncr, 'issued');
            $ncr->save();

            $this->activity->record(
                $ncr,
                'issued',
                $actor->name.' issued '.$this->label($ncr).'.',
                array_filter(['required_by' => $ncr->required_by?->toDateString()]),
                $actor,
                $this->projectIdOf($ncr),
            );

            NcrIssued::dispatch($ncr, (int) $actor->getKey());

            return $ncr;
        });
    }

    /**
     * The other side's account of it.
     *
     * Recorded even when it is disagreed with, and frequently the thing that changes the
     * disposition. An NCR carrying only the raiser's version is half a document.
     */
    public function respond(Ncr $ncr, User $actor, string $response): Ncr
    {
        if ($ncr->isDispositioned()) {
            throw new \DomainException($ncr->number.' has already been dispositioned.');
        }

        if (blank($response)) {
            throw new \DomainException('A response must say something.');
        }

        return DB::transaction(function () use ($ncr, $actor, $response) {
            $ncr->forceFill([
                'response' => $response,
                'responded_at' => now(),
                'responded_by' => $actor->getKey(),
            ]);

            $this->moveTo($ncr, 'responded');
            $ncr->save();

            $this->activity->record(
                $ncr,
                'responded',
                $actor->name.' responded to '.$this->label($ncr).'.',
                [],
                $actor,
                $this->projectIdOf($ncr),
            );

            return $ncr;
        });
    }

    /**
     * The engineering answer: rework, repair, use as is, reject, regrade.
     *
     * Three refusals, and each is the register's reason for existing: not twice, not by the person
     * who raised it, and not without notes when the decision leaves non-conforming work in place.
     */
    public function disposition(Ncr $ncr, User $actor, string $disposition, ?string $notes = null): Ncr
    {
        if ($ncr->isDispositioned()) {
            throw new \DomainException($ncr->number.' has already been dispositioned.');
        }

        if ((int) $ncr->detected_by === (int) $actor->getKey()
            || (int) $ncr->created_by === (int) $actor->getKey()) {
            throw new \DomainException('A non-conformance cannot be dispositioned by the person who raised it.');
        }

        if (blank($disposition)) {
            throw new \DomainException('A disposition must be chosen.');
        }

        if ($ncr->dispositionNeedsNotes($disposition) && blank($notes)) {
            throw new \DomainException(
                'A '.str_replace('_', ' ', $disposition).' disposition must record why it is acceptable.'
            );
        }

        return DB::transaction(function () use ($ncr, $actor, $disposition, $notes) {
            $ncr->forceFill([
                'disposition' => $disposition,
                'disposition_notes' => $notes,
                'dispositioned_at' => now(),
                'dispositioned_by' => $actor->getKey(),
            ]);

            $this->moveTo($ncr, 'dispositioned');
            $ncr->save();

            $this->activity->record(
                $ncr,
                'dispositioned',
                $actor->name.' dispositioned '.$this->label($ncr).' as '.str_replace('_', ' ', $disposition).'.',
                array_filter([
                    'disposition' => $disposition,
                    'cost_impact' => $ncr->cost_impact,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($ncr),
            );

            NcrDispositioned::dispatch($ncr, (int) $actor->getKey());

            return $ncr;
        });
    }

    /** Confirm the disposition was actually carried out. Weeks later, usually by somebody else. */
    public function verify(Ncr $ncr, User $actor, ?string $notes = null): Ncr
    {
        if (! $ncr->isDispositioned()) {
            throw new \DomainException($ncr->number.' has not been dispositioned yet.');
        }

        if ($ncr->isVerified()) {
            throw new \DomainException($ncr->number.' has already been verified.');
        }

        return DB::transaction(function () use ($ncr, $actor, $notes) {
            $ncr->forceFill([
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'verification_notes' => $notes,
            ]);

            $this->moveTo($ncr, 'verified');
            $ncr->save();

            $this->activity->record(
                $ncr,
                'verified',
                $actor->name.' verified the disposition of '.$this->label($ncr).'.',
                [],
                $actor,
                $this->projectIdOf($ncr),
            );

            return $ncr;
        });
    }

    /** Close it out. Only once the disposition has been verified — see the class docblock. */
    public function close(Ncr $ncr, User $actor, ?string $notes = null): Ncr
    {
        if ($ncr->isClosedOut()) {
            throw new \DomainException($ncr->number.' is already closed.');
        }

        if (! $ncr->isVerified()) {
            throw new \DomainException(
                $ncr->number.' cannot be closed until the disposition has been verified as carried out.'
            );
        }

        return DB::transaction(function () use ($ncr, $actor, $notes) {
            $ncr->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'closure_notes' => $notes,
            ]);

            $this->moveTo($ncr, 'closed');
            $ncr->save();

            $this->activity->record(
                $ncr,
                'closed',
                $actor->name.' closed '.$this->label($ncr).'.',
                [],
                $actor,
                $this->projectIdOf($ncr),
            );

            return $ncr;
        });
    }
}
