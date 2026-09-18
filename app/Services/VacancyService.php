<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Vacancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Asking for a head, and the route that decides it.
 *
 * A VACANCY IS A SPENDING DECISION. Somebody is about to be paid a salary for years, so the
 * requisition goes through the SAME workflow engine as a document, a leave request or a timesheet —
 * there is no `approve()` on this class and no approve route to reach past it with. The engine calls
 * back into `markApproved()`, which is the only code that can open a vacancy for applications.
 *
 * APPROVED MEANS OPEN, and the status says so rather than saying "approved". Applications can only
 * be taken against an open vacancy (see ApplicantService), so a head nobody agreed to cannot quietly
 * acquire a shortlist and an offer.
 *
 * FILLING IS COUNTED, NOT DECLARED. `hired_count` is incremented by ApplicantService when somebody
 * is actually taken on, and the vacancy closes itself at the number of openings that were approved.
 * A recruiter cannot hire four people against a head approved for two by editing a field.
 */
class VacancyService extends RoutedRecordService
{
    protected function entityType(): string
    {
        return 'vacancy';
    }

    protected function modelClass(): string
    {
        return Vacancy::class;
    }

    protected function noun(): string
    {
        return 'vacancy';
    }

    /** A vacancy that got through its route is OPEN. See the class docblock. */
    protected function approvedStatusKey(): string
    {
        return 'open';
    }

    /**
     * The department is a FORM FIELD here, unlike on leave and timesheets.
     *
     * A leave request's org unit is a fact about the person taking the leave and is copied from
     * their personnel file. A vacancy's org unit is the question being asked — which department
     * wants the head — so it is chosen, validated against the tenant in VacancyRequest, and left out
     * of this deny list. The counters below stay in it: `hired_count` is the one field a recruiter
     * would most like to be able to type.
     */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'requester_id', 'confidentiality', 'hired_count',
            'submitted_at', 'decided_at', 'decision_reason',
            'closed_at', 'closure_reason',
        ];
    }

    /** The person asking is the person the route starts from, exactly as on leave and timesheets. */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Vacancy $record */
        $record->setAttribute('requester_id', $actor->getKey());

        $this->checkShape($record);
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Vacancy $record */
        $this->requireDraft($record, 'changed');

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->checkShape($record);

            return parent::update($record, [], $actor);
        });
    }

    public function submit(Vacancy $vacancy, User $actor): Vacancy
    {
        $this->requireDraft($vacancy, 'submitted');

        return DB::transaction(function () use ($vacancy, $actor) {
            $this->checkShape($vacancy);

            $this->route(
                $vacancy,
                $actor,
                $actor->name.' asked for '.$vacancy->openings.' × '.$vacancy->title.'.',
            );

            return $vacancy;
        });
    }

    /**
     * Stop looking, with a reason.
     *
     * Distinct from `filled`: one says the company got who it wanted, the other says it changed its
     * mind or ran out of budget, and a register that cannot tell them apart cannot answer why a
     * department is still short-handed.
     */
    public function close(Vacancy $vacancy, User $actor, string $reason): Vacancy
    {
        if (! in_array($vacancy->statusKey(), ['draft', 'submitted', 'open'], true)) {
            throw new \DomainException('This vacancy is already closed.');
        }

        if (blank($reason)) {
            throw new \DomainException('Say why the vacancy is being closed.');
        }

        return DB::transaction(function () use ($vacancy, $actor, $reason) {
            $vacancy->forceFill(['closed_at' => now(), 'closure_reason' => $reason]);
            $this->moveTo($vacancy, 'closed');
            $vacancy->updated_by = $actor->getKey();
            $vacancy->save();

            // A requisition awaiting a decision that has been withdrawn must not stay in somebody's
            // approvals inbox — the head no longer exists to approve.
            $this->closeRoute($vacancy, $actor, $reason);

            $this->activity->record($vacancy, 'closed', $actor->name.' closed '.$this->label($vacancy).'.', ['reason' => $reason], $actor);

            return $vacancy;
        });
    }

    /**
     * One more head taken, counted against what was approved.
     *
     * Called by ApplicantService inside the hiring transaction, with the vacancy row locked, so two
     * recruiters hiring at the same moment cannot both read `hired_count` as one short of the limit.
     */
    public function recordHire(Vacancy $vacancy, User $actor): Vacancy
    {
        $vacancy->hired_count = $vacancy->hired_count + 1;

        if ($vacancy->hired_count >= $vacancy->openings) {
            $this->moveTo($vacancy, 'filled');
            $vacancy->closed_at = now();
        }

        $vacancy->updated_by = $actor->getKey();
        $vacancy->save();

        if ($vacancy->statusKey() === 'filled') {
            $this->activity->record($vacancy, 'filled', $this->label($vacancy).' is filled.', [], $actor);
        }

        return $vacancy;
    }

    /** Whether applications may still be taken — asked by ApplicantService before it writes one. */
    public function requireOpen(Vacancy $vacancy): void
    {
        if ($vacancy->statusKey() !== 'open') {
            throw new \DomainException('Applications can only be taken against an approved, open vacancy.');
        }

        if ($vacancy->closed_at !== null) {
            throw new \DomainException('This vacancy has been closed.');
        }
    }

    private function checkShape(Vacancy $vacancy): void
    {
        if (blank($vacancy->title)) {
            throw new \DomainException('Give the vacancy a title.');
        }

        $openings = (int) $vacancy->openings;

        if ($openings < 1 || $openings > 999) {
            throw new \DomainException('A vacancy must be for between 1 and 999 people.');
        }

        if ($openings < $vacancy->hired_count) {
            throw new \DomainException('Somebody has already been hired against this vacancy, so it cannot be reduced below '.$vacancy->hired_count.'.');
        }

        if ($vacancy->closes_on !== null && $vacancy->closes_on->lt(CarbonImmutable::today())) {
            throw new \DomainException('The closing date cannot be in the past.');
        }
    }
}
