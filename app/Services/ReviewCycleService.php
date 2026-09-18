<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\ReviewCycleCompetency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An appraisal round: the period, the questions, and the act of opening it for everybody at once.
 *
 * THE CYCLE IS NOT CONFIDENTIAL AND THE REVIEWS ARE. Everybody in the company can see that the
 * mid-year round runs from July to September and asks about six competencies — that is a company
 * announcement, not a secret. What people said about each other lives on `performance_reviews`,
 * which starts restricted. Splitting the two is what lets the cycle be published without publishing
 * anybody's rating (§68).
 *
 * OPENING IS A COPY, NOT A LINK. Each review takes its own snapshot of the competencies as items,
 * so a cycle edited in week three does not silently change the questions people already answered in
 * week one. The same reasoning as a checklist copied from its template, and the same reason a
 * contract keeps its own rates rather than reading today's.
 *
 * OPENING IS RESUMABLE. It skips anybody who already has a review in this cycle — the unique index
 * on (cycle, employee) is the guarantee, this is the courtesy — so a round interrupted halfway, or
 * a new starter who joined after it opened, is handled by running it again rather than by unpicking
 * what already exists.
 */
class ReviewCycleService extends RegisterService
{
    protected function entityType(): string
    {
        return 'review_cycle';
    }

    protected function modelClass(): string
    {
        return ReviewCycle::class;
    }

    /**
     * A cycle is called "2026 Mid-year", not "RVC-2026-0001". See numberColumn()'s own docblock —
     * a generated identifier nobody quotes is a column nobody reads.
     */
    protected function numberColumn(): ?string
    {
        return null;
    }

    protected function protectedAttributes(): array
    {
        return ['status_id', 'competencies'];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var ReviewCycle $record */
        $this->checkShape($record);
    }

    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var ReviewCycle $record */
        if (isset($attributes['competencies'])) {
            $this->syncCompetencies($record, (array) $attributes['competencies'], $actor);
        }
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var ReviewCycle $record */
        return DB::transaction(function () use ($record, $attributes, $actor) {
            // The dates and the scale are what the reviews were built against. Once somebody has
            // started answering, changing the scale underneath them would turn a 4-out-of-5 into a
            // 4-out-of-10 in every review already written.
            if ($record->reviews()->exists()) {
                unset($attributes['rating_min'], $attributes['rating_max']);
            }

            $record->fill($this->safeAttributes($attributes));
            $this->checkShape($record);

            $updated = parent::update($record, [], $actor);

            if (isset($attributes['competencies'])) {
                $this->syncCompetencies($record, (array) $attributes['competencies'], $actor);
            }

            return $updated;
        });
    }

    /**
     * Publish the cycle and open a review for everybody it covers.
     *
     * @param  array<int,int>|null  $orgUnitIds  limit the round to some departments; null means all
     * @return int the number of reviews opened
     */
    public function open(ReviewCycle $cycle, User $actor, ?array $orgUnitIds = null): int
    {
        if ($cycle->statusKey() === 'closed' || $cycle->statusKey() === 'cancelled') {
            throw new \DomainException('This cycle has finished. Start a new one.');
        }

        if ($cycle->competencies()->count() === 0) {
            throw new \DomainException('Add at least one competency or objective before opening the cycle.');
        }

        return DB::transaction(function () use ($cycle, $actor, $orgUnitIds) {
            $competencies = $cycle->competencies()->orderBy('sequence')->get();

            $already = PerformanceReview::query()
                ->where('review_cycle_id', $cycle->getKey())
                ->pluck('employee_id')
                ->all();

            $employees = Employee::query()
                ->active()
                ->when($orgUnitIds !== null && $orgUnitIds !== [], fn ($q) => $q->whereIn('org_unit_id', $orgUnitIds))
                ->whereNotIn('id', $already === [] ? [0] : $already)
                ->with(['supervisor'])
                ->get();

            $opened = 0;

            foreach ($employees as $employee) {
                if ($this->openFor($cycle, $employee, $competencies, $actor) !== null) {
                    $opened++;
                }
            }

            if ($cycle->statusKey() !== 'open') {
                $this->moveTo($cycle, 'open');
                $cycle->save();
            }

            $this->activity->record(
                $cycle,
                'opened',
                $actor->name.' opened '.$cycle->name.' and started '.$opened.' review'.($opened === 1 ? '' : 's').'.',
                ['reviews' => $opened],
                $actor,
            );

            return $opened;
        });
    }

    /**
     * One review, with the cycle's questions copied onto it.
     *
     * Returns null for somebody who cannot be reviewed yet rather than throwing, because opening a
     * round of two hundred must not fail on one person with no supervisor recorded — they are
     * reported back on screen and given a reviewer by hand.
     *
     * @param  Collection<int,ReviewCycleCompetency>  $competencies
     */
    public function openFor(ReviewCycle $cycle, Employee $employee, $competencies, User $actor, ?int $reviewerId = null): ?PerformanceReview
    {
        // The manager, unless somebody names another reviewer. A person with no supervisor recorded
        // has nobody to answer the manager half, so their review is not opened blind.
        $reviewerId ??= $employee->supervisor?->user_id;

        if ($reviewerId === null) {
            return null;
        }

        $review = new PerformanceReview([
            'review_cycle_id' => $cycle->getKey(),
            'employee_id' => $employee->getKey(),
            // Denormalised so "my review" is answerable at `own` scope. See the migration.
            'user_id' => $employee->user_id,
            'reviewer_id' => $reviewerId,
            'org_unit_id' => $employee->org_unit_id,
        ]);

        $review->organization_id = $cycle->organization_id;    // from the cycle, never input (§74)
        $review->created_by = $actor->getKey();
        $review->number = $this->numbers->next('performance_review');
        $review->confidentiality = $review->defaultConfidentiality();
        $review->status_id = $this->statuses->initialFor('performance_review')?->id;
        $review->save();

        foreach ($competencies as $index => $competency) {
            /** @var ReviewCycleCompetency $competency */
            if (! $competency->appliesTo($employee)) {
                continue;
            }

            $review->items()->create([
                'organization_id' => $review->organization_id,
                'label' => $competency->label,
                'description' => $competency->description,
                'kind' => $competency->kind,
                'weight' => $competency->weight,
                'sequence' => $competency->sequence ?? $index,
            ]);
        }

        // The manager's key to this one review. A review is restricted and its reviewer is often
        // outside the department it is filed under, so reach has to be given per record rather than
        // by widening anybody's scope. See PerformanceReviewService::assignReviewerAccess().
        app(PerformanceReviewService::class)->assignReviewerAccess($review, $actor);

        $this->activity->record(
            $review,
            'created',
            'A review was opened for '.$employee->fullName().' in '.$cycle->name.'.',
            ['number' => $review->number],
            $actor,
        );

        return $review;
    }

    /**
     * Finish the round.
     *
     * Refuses while reviews are still in flight, and says how many. A closed cycle with live
     * reviews inside it is a cycle whose reviews have nowhere to go: the subject cannot finish
     * theirs and the manager cannot sign it off, so the work sits there looking like somebody's
     * fault. Cancel the stragglers first — that is a decision a person makes one by one.
     */
    public function close(ReviewCycle $cycle, User $actor): ReviewCycle
    {
        if ($cycle->statusKey() !== 'open') {
            throw new \DomainException('Only an open cycle can be closed.');
        }

        return DB::transaction(function () use ($cycle, $actor) {
            $outstanding = $cycle->reviews()
                ->whereHas('status', fn ($q) => $q->whereNotIn('key', ['completed', 'rejected', 'cancelled']))
                ->count();

            if ($outstanding > 0) {
                throw new \DomainException(
                    $outstanding.' review'.($outstanding === 1 ? ' is' : 's are').' still open. Finish or cancel '
                    .($outstanding === 1 ? 'it' : 'them').' before closing the cycle.'
                );
            }

            $this->moveTo($cycle, 'closed');
            $cycle->save();

            $this->activity->record($cycle, 'closed', $actor->name.' closed '.$cycle->name.'.', [], $actor);

            return $cycle;
        });
    }

    public function cancel(ReviewCycle $cycle, string $reason, User $actor): ReviewCycle
    {
        if (in_array($cycle->statusKey(), ['closed', 'cancelled'], true)) {
            throw new \DomainException('This cycle has already finished.');
        }

        return DB::transaction(function () use ($cycle, $reason, $actor) {
            $this->moveTo($cycle, 'cancelled');
            $cycle->save();

            $this->activity->record($cycle, 'cancelled', $actor->name.' cancelled '.$cycle->name.': '.$reason, [], $actor);

            return $cycle;
        });
    }

    // ================================================================== internals

    /**
     * Replace the cycle's questions.
     *
     * Only while nothing has been answered. Afterwards the questions are frozen, because the items
     * already copied onto reviews would no longer match the cycle they were copied from and every
     * comparison across the round — the only reason a cycle exists rather than loose reviews — would
     * be between two different question sets.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function syncCompetencies(ReviewCycle $cycle, array $rows, User $actor): void
    {
        if ($cycle->reviews()->exists()) {
            throw new \DomainException('Reviews have already been opened against this cycle, so its questions can no longer change.');
        }

        $keep = [];

        foreach (array_values($rows) as $index => $row) {
            $label = trim((string) ($row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $kind = in_array($row['kind'] ?? null, ReviewCycleCompetency::KINDS, true)
                ? $row['kind']
                : ReviewCycleCompetency::KIND_COMPETENCY;

            $attributes = [
                'organization_id' => $cycle->organization_id,
                'label' => $label,
                'description' => $row['description'] ?? null,
                'kind' => $kind,
                // A blank weight box means "the same as everything else", not "no weight". The
                // column carries a default of 1 for exactly that reason, and writing null over it
                // would refuse the save — a company that scores six competencies equally should
                // never have to type 1 six times to be allowed to.
                'weight' => filled($row['weight'] ?? null) ? $row['weight'] : 1,
                'sequence' => $index,
                'org_unit_id' => $row['org_unit_id'] ?? null,
                'position_id' => $row['position_id'] ?? null,
            ];

            $existing = filled($row['id'] ?? null)
                ? $cycle->competencies()->whereKey($row['id'])->first()
                : null;

            if ($existing !== null) {
                $existing->fill($attributes);
                $existing->save();
                $keep[] = $existing->getKey();

                continue;
            }

            $keep[] = $cycle->competencies()->create($attributes)->getKey();
        }

        if ($keep === []) {
            throw new \DomainException('A cycle needs at least one competency or objective.');
        }

        $cycle->competencies()->whereKeyNot($keep)->delete();

        $this->activity->record($cycle, 'updated', $actor->name.' changed the questions on '.$cycle->name.'.', ['competencies' => count($keep)], $actor);
    }

    private function checkShape(ReviewCycle $cycle): void
    {
        if (trim((string) $cycle->name) === '') {
            throw new \DomainException('Give the cycle a name.');
        }

        if ($cycle->period_start === null || $cycle->period_end === null) {
            throw new \DomainException('Say which period this cycle reviews.');
        }

        if ($cycle->period_end->lt($cycle->period_start)) {
            throw new \DomainException('The period ends before it starts.');
        }

        if ($cycle->opens_on !== null && $cycle->closes_on !== null && $cycle->closes_on->lt($cycle->opens_on)) {
            throw new \DomainException('The cycle closes before it opens.');
        }

        // The scale is company data, not a constant — a company that rates out of four is not
        // wrong, and one that rates 0–10 is not either (§47 in spirit: no hard-coded conventions).
        if ((float) $cycle->rating_max <= (float) $cycle->rating_min) {
            throw new \DomainException('The top of the rating scale must be above the bottom.');
        }

        if ((float) $cycle->rating_max - (float) $cycle->rating_min > 100) {
            throw new \DomainException('That rating scale is too wide to be usable.');
        }
    }
}
