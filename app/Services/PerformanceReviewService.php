<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PerformanceReview;
use App\Models\PerformanceReviewItem;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Concerns\GrantsRecordAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One person's appraisal: their own words first, then their manager's, then a sign-off route.
 *
 * THE ORDER IS THE POINT. A review that opened on the manager's half would be an assessment done to
 * somebody; opening on the subject's half makes it a conversation, and the two score columns exist
 * side by side because the GAP between them is what the meeting is actually about. Nothing here
 * averages them — a self-score of 5 against a manager score of 2 is a disagreement to be talked
 * about, not an arithmetic mean of 3.5 to be filed.
 *
 * SIGN-OFF IS A ROUTE, NOT A BUTTON. There is no `approve()` on this class and no approve route in
 * `web.php`; a completed review is one the workflow engine decided at `approvals.decide`, exactly as
 * a leave request or a timesheet is. That is Phase 6's exit criterion, and performance is the case
 * that tests it hardest — the obvious shortcut here is "the HR manager marks it done".
 *
 * RETURNED MEANS BACK TO THE MANAGER, not back to draft. The default in RoutedRecordService sends a
 * returned record to its author as a draft; doing that here would throw away the subject's
 * self-assessment and make them write it again because their manager's wording was wrong.
 *
 * WHAT THE SUBJECT CAN SEE is their own review, always — they hold `user_id`, which is why that
 * column is denormalised onto the table. What they cannot see is anybody else's, because the record
 * is restricted and `performance.view_confidential` is a sensitive permission granted to HR.
 */
class PerformanceReviewService extends RoutedRecordService
{
    use GrantsRecordAccess;

    protected function entityType(): string
    {
        return 'performance_review';
    }

    protected function modelClass(): string
    {
        return PerformanceReview::class;
    }

    protected function noun(): string
    {
        return 'performance review';
    }

    /** A review is not "approved", it is finished. See RoutedRecordService::approvedStatusKey(). */
    protected function approvedStatusKey(): string
    {
        return 'completed';
    }

    protected function protectedAttributes(): array
    {
        return [
            ...parent::protectedAttributes(),
            // Who is being reviewed, by whom, and in which round: settled when the cycle opened.
            'review_cycle_id', 'employee_id', 'user_id',
            // Everything written by an act somebody was authorized to perform, never by a form.
            'self_comments', 'self_submitted_at', 'manager_comments', 'manager_submitted_at',
            'overall_rating', 'development_plan', 'acknowledged_at', 'acknowledgement_comments',
        ];
    }

    /**
     * Change the reviewer, or the department the review is filed under.
     *
     * The only thing HR edits directly. Everything else on the record is somebody's answer to a
     * question, and answers are written by the methods below.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var PerformanceReview $record */
        if ($record->isFinal()) {
            throw new \DomainException('This review is finished and can no longer be changed.');
        }

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $previous = $record->reviewer_id;

            $updated = parent::update($record, $attributes, $actor);

            // A reviewer who changes takes the key with them. The new one needs it to open the
            // review at all — see assignReviewerAccess().
            if ($record->reviewer_id !== $previous) {
                $this->revokeRecordAccess($record, $previous);
                $this->assignReviewerAccess($record, $actor);
            }

            return $updated;
        });
    }

    /**
     * Give the reviewer the key to this one review.
     *
     * A review is restricted, and a manager frequently does not sit in the department the review is
     * filed under — a section head appraising somebody seconded elsewhere, an acting supervisor
     * standing in. Without an explicit row the person the platform just told to write a review would
     * open it and be refused, and the fix somebody would reach for is granting them
     * `performance.view_confidential`, which is every review in the company.
     */
    public function assignReviewerAccess(PerformanceReview $review, ?User $actor = null): void
    {
        // Fetched rather than lazy-loaded: this runs in a loop when a cycle opens, and under
        // preventLazyLoading() a relation touched off a freshly saved model is an exception.
        $reviewer = $review->relationLoaded('reviewer')
            ? $review->reviewer
            : User::query()->find($review->reviewer_id);

        if ($reviewer === null) {
            return;
        }

        $this->grantRecordAccess($review, $reviewer, $actor, 'Reviewer on this appraisal.');
    }

    /**
     * Hand the review to the person it is about.
     *
     * Separate from opening it, because a cycle opens two hundred reviews at once and HR may want
     * to check the reviewers before anybody starts writing.
     */
    public function release(PerformanceReview $review, User $actor): PerformanceReview
    {
        if ($review->statusKey() !== 'draft') {
            throw new \DomainException('This review has already been started.');
        }

        return DB::transaction(function () use ($review, $actor) {
            $this->moveTo($review, 'self_assessment');
            $review->save();

            $this->activity->record(
                $review,
                'released',
                $actor->name.' asked '.$review->subjectName().' to complete their self-assessment.',
                [],
                $actor,
                null,
                $review->org_unit_id,
            );

            return $review;
        });
    }

    /**
     * The subject's half: their own scores and comments, saved as often as they like.
     *
     * @param  array<int,array<string,mixed>>  $items  keyed by review item id
     */
    public function saveSelfAssessment(PerformanceReview $review, array $items, ?string $comments, User $actor): PerformanceReview
    {
        if (! in_array($review->statusKey(), ['draft', 'self_assessment'], true)) {
            throw new \DomainException('The self-assessment on this review has already been sent to the manager.');
        }

        return DB::transaction(function () use ($review, $items, $comments, $actor) {
            $this->writeScores($review, $items, 'self');

            $review->forceFill(['self_comments' => $comments]);

            if ($review->statusKey() === 'draft') {
                $this->moveTo($review, 'self_assessment');
            }

            $review->save();

            $this->activity->record($review, 'updated', $review->subjectName().' worked on their self-assessment.', [], $actor, null, $review->org_unit_id);

            return $review;
        });
    }

    /** Send the self-assessment to the manager. The subject's own scores are frozen after this. */
    public function submitSelfAssessment(PerformanceReview $review, User $actor): PerformanceReview
    {
        if ($review->statusKey() !== 'self_assessment') {
            throw new \DomainException('This self-assessment cannot be sent from here.');
        }

        return DB::transaction(function () use ($review, $actor) {
            $review->forceFill(['self_submitted_at' => now()]);
            $this->moveTo($review, 'manager_review');
            $review->save();

            $this->activity->record(
                $review,
                'submitted',
                $review->subjectName().' completed their self-assessment.',
                [],
                $actor,
                null,
                $review->org_unit_id,
            );

            return $review;
        });
    }

    /**
     * The manager's half: their scores, their comments, the overall rating and the plan.
     *
     * @param  array<int,array<string,mixed>>  $items  keyed by review item id
     * @param  array<string,mixed>  $summary
     */
    public function saveManagerReview(PerformanceReview $review, array $items, array $summary, User $actor): PerformanceReview
    {
        if ($review->statusKey() !== 'manager_review') {
            throw new \DomainException('This review is not with the manager.');
        }

        return DB::transaction(function () use ($review, $items, $summary, $actor) {
            $this->writeScores($review, $items, 'manager');

            $overall = $summary['overall_rating'] ?? null;

            if (filled($overall)) {
                $this->checkScore((float) $overall, $review);
            }

            $review->forceFill([
                'manager_comments' => $summary['manager_comments'] ?? $review->manager_comments,
                'overall_rating' => filled($overall) ? $overall : null,
                'development_plan' => $summary['development_plan'] ?? $review->development_plan,
            ]);
            $review->save();

            $this->activity->record($review, 'updated', $actor->name.' worked on '.$review->subjectName().'\'s review.', [], $actor, null, $review->org_unit_id);

            return $review;
        });
    }

    /** Send the finished review for sign-off. From here the workflow engine decides it. */
    public function submitForSignOff(PerformanceReview $review, User $actor): PerformanceReview
    {
        if ($review->statusKey() !== 'manager_review') {
            throw new \DomainException('Only a review the manager has finished can be sent for sign-off.');
        }

        return DB::transaction(function () use ($review, $actor) {
            $review->loadMissing('items');

            if (filled($review->overall_rating) === false) {
                throw new \DomainException('Give an overall rating before sending this for sign-off.');
            }

            $unscored = $review->items->filter(fn (PerformanceReviewItem $item) => $item->manager_score === null)->count();

            if ($unscored > 0) {
                throw new \DomainException($unscored.' item'.($unscored === 1 ? ' has' : 's have').' no manager score yet.');
            }

            $review->forceFill(['manager_submitted_at' => now()]);

            $this->route($review, $actor, $actor->name.' sent '.$review->subjectName().'\'s review for sign-off.');

            return $review;
        });
    }

    /**
     * Back to the manager, not back to the start. See the class docblock.
     */
    public function markReturned(Model $record, WorkflowInstance $instance): void
    {
        /** @var PerformanceReview $record */
        $record->forceFill(['submitted_at' => null, 'manager_submitted_at' => null, 'decided_at' => null]);
        $this->moveTo($record, 'manager_review');
        $record->save();

        $this->activity->record(
            $record,
            'returned',
            $this->label($record).' was sent back to the manager through its approval route.',
            array_filter(['workflow_instance' => $instance->getKey()]),
            null,
            null,
            $record->org_unit_id,
        );
    }

    /**
     * The subject says they have seen it.
     *
     * Acknowledgement is NOT agreement, and the comment box is there so somebody can say so. A
     * review acknowledged under protest is a better record than one nobody was shown.
     */
    public function acknowledge(PerformanceReview $review, ?string $comments, User $actor): PerformanceReview
    {
        if ($review->statusKey() !== 'completed') {
            throw new \DomainException('This review has not been signed off yet.');
        }

        if ($review->user_id !== $actor->getKey()) {
            throw new \DomainException('Only the person being reviewed can acknowledge it.');
        }

        if ($review->acknowledged_at !== null) {
            throw new \DomainException('This review has already been acknowledged.');
        }

        return DB::transaction(function () use ($review, $comments, $actor) {
            $review->forceFill(['acknowledged_at' => now(), 'acknowledgement_comments' => $comments]);
            $review->save();

            $this->activity->record($review, 'acknowledged', $actor->name.' acknowledged their review.', [], $actor, null, $review->org_unit_id);

            return $review;
        });
    }

    public function cancel(PerformanceReview $review, string $reason, User $actor): PerformanceReview
    {
        if ($review->isFinal()) {
            throw new \DomainException('This review has already finished.');
        }

        return DB::transaction(function () use ($review, $reason, $actor) {
            $this->closeRoute($review, $actor, $reason);

            $review->forceFill(['decision_reason' => $reason, 'decided_at' => now()]);
            $this->moveTo($review, 'cancelled');
            $review->save();

            $this->activity->record($review, 'cancelled', $actor->name.' cancelled this review: '.$reason, [], $actor, null, $review->org_unit_id);

            return $review;
        });
    }

    // ================================================================== internals

    /**
     * Write one side of the scores, refusing anything that is not an item of THIS review.
     *
     * The posted keys are item ids from a form, which is exactly the shape §84 and §94 are about:
     * a manager who edits the id in the payload must not reach an item on somebody else's review.
     * The items are loaded from the review's own relation and the payload is matched against them,
     * so an unknown id is ignored rather than trusted.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  'self'|'manager'  $side
     */
    private function writeScores(PerformanceReview $review, array $rows, string $side): void
    {
        $review->loadMissing('items');

        foreach ($review->items as $item) {
            $row = $rows[$item->getKey()] ?? null;

            if ($row === null) {
                continue;
            }

            $score = $row[$side.'_score'] ?? null;

            if (filled($score)) {
                $this->checkScore((float) $score, $review);
            }

            $item->forceFill([
                $side.'_score' => filled($score) ? $score : null,
                $side.'_comment' => $row[$side.'_comment'] ?? null,
            ]);
            $item->save();
        }
    }

    /**
     * A score has to sit on the cycle's scale.
     *
     * Checked here rather than in the form request because the scale is per cycle and per tenant —
     * a company that rates out of four and a company that rates out of ten are both right, and a
     * validation rule with a `max:5` in it would be the platform having an opinion about somebody
     * else's appraisal policy.
     */
    private function checkScore(float $score, PerformanceReview $review): void
    {
        // Loaded here rather than assumed, because this runs once per item on a form with a dozen
        // of them: left to lazy loading it is one query per score saved (§89), and under the
        // lazy-loading guard it is not a slow save but a failed one.
        $review->loadMissing('cycle');

        $cycle = $review->cycle;

        if ($cycle === null) {
            return;
        }

        if (! $cycle->allowsScore($score)) {
            throw new \DomainException('Scores on this cycle run from '.$cycle->scaleLabel().'.');
        }
    }
}
