<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RiskAssessment;
use App\Models\RiskAssessmentHazard;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How the work will be done safely.
 *
 * RISK SCORES ARE COMPUTED HERE, NEVER ACCEPTED FROM A FORM. `likelihood × severity` is arithmetic,
 * and a submitted `risk_score` that disagrees with its own factors is how a register ends up
 * reporting a green figure for a red hazard. The two factor columns are the input; the score is
 * derived on every write, on both the initial and the residual pair.
 *
 * NO MATRIX IS HARD-CODED BEYOND THE MULTIPLICATION. A 5×5 matrix and a 3×3 matrix both work
 * because nothing here assumes a range — RiskAssessmentHazard::band() takes the matrix maximum as a
 * parameter for the same reason. Companies genuinely differ on this and the difference is not one a
 * developer should be settling (§47's shape, applied to safety rather than tax).
 *
 * APPROVAL FREEZES THE DOCUMENT, and the service refuses a second approval as well as the policy
 * refusing edits afterwards. A risk assessment is what a crew is briefed to; if the version that was
 * approved and the version that was briefed can differ, the document has stopped being evidence.
 * A change is a revision — supersede this one and issue the next.
 */
class RiskAssessmentService extends RegisterService
{
    protected function entityType(): string
    {
        return 'hse_risk';
    }

    protected function modelClass(): string
    {
        return RiskAssessment::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'reviewed_by', 'reviewed_at',
            'approved_by', 'approved_at',
        ];
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var RiskAssessment $record */
        if ($record->assessed_by === null) {
            $record->assessed_by = $actor->getKey();
        }

        if ($record->assessed_on === null) {
            $record->assessed_on = now()->toDateString();
        }

        // Derived from the review frequency when the form did not supply an end date, because an
        // assessment with no `valid_to` never appears on the expiry report — which is the one report
        // this register exists to produce.
        if ($record->valid_to === null && $record->review_frequency_months) {
            $from = $record->valid_from ?? now();
            $record->valid_to = $from->copy()->addMonths((int) $record->review_frequency_months)->toDateString();
        }
    }

    /**
     * Replace the hazard rows wholesale.
     *
     * Wholesale rather than row-by-row because a hazard sheet is edited as a table: rows are added,
     * reordered and deleted in one pass, and reconciling that as individual operations produces a
     * form that silently drops deletions. The rows carry no independent history — the assessment's
     * revision is the unit of history — so replacing them loses nothing.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function setHazards(RiskAssessment $assessment, array $rows, User $actor): RiskAssessment
    {
        if ($assessment->isApproved()) {
            throw new \DomainException(
                $assessment->number.' was approved and cannot be changed. Issue a revision instead.'
            );
        }

        return DB::transaction(function () use ($assessment, $rows, $actor) {
            $assessment->hazards()->delete();

            foreach (array_values($rows) as $index => $row) {
                if (blank($row['hazard'] ?? null)) {
                    continue;
                }

                $hazard = new RiskAssessmentHazard;
                $hazard->organization_id = $assessment->organization_id;
                $hazard->risk_assessment_id = $assessment->getKey();
                $hazard->sort_order = $index;

                $hazard->hazard = $row['hazard'];
                $hazard->who_is_at_risk = $row['who_is_at_risk'] ?? null;
                $hazard->existing_controls = $row['existing_controls'] ?? null;
                $hazard->additional_controls = $row['additional_controls'] ?? null;

                $hazard->likelihood = $this->factor($row['likelihood'] ?? null);
                $hazard->severity = $this->factor($row['severity'] ?? null);
                $hazard->risk_score = $this->score($hazard->likelihood, $hazard->severity);

                $hazard->residual_likelihood = $this->factor($row['residual_likelihood'] ?? null);
                $hazard->residual_severity = $this->factor($row['residual_severity'] ?? null);
                $hazard->residual_score = $this->score($hazard->residual_likelihood, $hazard->residual_severity);

                $hazard->responsible_id = $row['responsible_id'] ?? null;
                $hazard->target_date = $row['target_date'] ?? null;

                $hazard->save();
            }

            $this->activity->record(
                $assessment,
                'updated',
                $actor->name.' updated the hazards on '.$this->label($assessment).'.',
                ['hazards' => $assessment->hazards()->count()],
                $actor,
                $this->projectIdOf($assessment),
            );

            return $assessment->load('hazards');
        });
    }

    /** The competent-person check. */
    public function review(RiskAssessment $assessment, User $actor): RiskAssessment
    {
        if ($assessment->isApproved()) {
            throw new \DomainException($assessment->number.' has already been approved.');
        }

        return DB::transaction(function () use ($assessment, $actor) {
            $assessment->forceFill([
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            $this->moveTo($assessment, 'in_review');
            $assessment->save();

            $this->activity->record(
                $assessment,
                'reviewed',
                $actor->name.' reviewed '.$this->label($assessment).'.',
                [],
                $actor,
                $this->projectIdOf($assessment),
            );

            return $assessment;
        });
    }

    /**
     * Accept the residual risk on the company's behalf.
     *
     * Refuses the assessor in the service as well as in the policy, and refuses an assessment with
     * no hazards on it at all — an empty risk assessment approved is a signature on a blank page,
     * and it is a surprisingly common thing to find in a register nobody checked.
     */
    public function approve(RiskAssessment $assessment, User $actor): RiskAssessment
    {
        if ($assessment->isApproved()) {
            throw new \DomainException($assessment->number.' has already been approved.');
        }

        if ((int) $assessment->assessed_by === (int) $actor->getKey()
            || (int) $assessment->created_by === (int) $actor->getKey()) {
            throw new \DomainException('A risk assessment cannot be approved by the person who wrote it.');
        }

        if ($assessment->hazards()->doesntExist()) {
            throw new \DomainException($assessment->number.' has no hazards recorded and cannot be approved.');
        }

        return DB::transaction(function () use ($assessment, $actor) {
            $assessment->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);

            $this->moveTo($assessment, 'approved');
            $assessment->save();

            $this->activity->record(
                $assessment,
                'approved',
                $actor->name.' approved '.$this->label($assessment).'.',
                array_filter(['highest_residual' => $assessment->highestResidualScore()]),
                $actor,
                $this->projectIdOf($assessment),
            );

            return $assessment;
        });
    }

    // ================================================================== internals

    private function factor(mixed $value): ?int
    {
        return filled($value) ? max(0, (int) $value) : null;
    }

    private function score(?int $likelihood, ?int $severity): ?int
    {
        return ($likelihood === null || $severity === null) ? null : $likelihood * $severity;
    }
}
