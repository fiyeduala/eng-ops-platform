<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PerformanceReview;
use App\Models\PerformanceReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's appraisal, and who may do what to it at each stage.
 *
 * THERE IS NO `approve()` HERE, and that is the point of Phase 6. A review is signed off by the
 * workflow engine at `approvals.decide`, exactly as a leave request or a timesheet is — so the
 * question "who may approve this" is answered by the route an administrator configured, not by a
 * permission somebody could be granted by accident.
 *
 * WHO IS WHO IS READ OFF THE RECORD, never off the request. The subject is `user_id` and the
 * reviewer is `reviewer_id`; both are stamped when the cycle opens and neither is a form field (§84).
 * Two people therefore get abilities nobody granted them by role — the subject may write their own
 * half and acknowledge the outcome, and the reviewer may write theirs — and both are gated on the
 * status as well, so neither can reach past their own turn.
 *
 * THE SUBJECT CANNOT EDIT THE RECORD. `update` is HR's, and it changes the reviewer or the filing
 * department. Everything a subject or a manager writes goes through a named act on the service,
 * because "the employee may edit their review" and "the employee may write their self-assessment"
 * are very different sentences.
 *
 * THE ITEMS ARE BOUND TO THIS POLICY. An item endpoint answering to its own rules would be a way to
 * read a restricted review one line at a time (§94).
 */
class PerformanceReviewPolicy extends ScopedPolicy
{
    protected string $module = 'performance';

    public function view(User $user, Model $record): bool
    {
        return parent::view($user, $this->subject($record));
    }

    public function update(User $user, Model $record): bool
    {
        $review = $this->subject($record);

        return $review instanceof PerformanceReview
            && ! $review->isFinal()
            && parent::update($user, $review);
    }

    public function delete(User $user, Model $record): bool
    {
        $review = $this->subject($record);

        // A signed-off review is the company's record of a conversation that happened. Cancelling
        // an unfinished one is the way out; deleting a finished one is not.
        return $review instanceof PerformanceReview
            && ! $review->isFinal()
            && parent::delete($user, $review);
    }

    /** Hand the review to the person it is about. HR's act, not the manager's. */
    public function release(User $user, PerformanceReview $review): bool
    {
        return $review->statusKey() === 'draft' && $this->check($user, 'manage', $review);
    }

    /**
     * The subject's own half.
     *
     * Being the subject is necessary but not sufficient: they still need `performance.submit`, which
     * the base role template grants at `own` scope, so a company that runs its appraisals on paper
     * can turn the whole thing off by not granting it.
     */
    public function selfAssess(User $user, PerformanceReview $review): bool
    {
        return $review->user_id === $user->getKey()
            && in_array($review->statusKey(), ['draft', 'self_assessment'], true)
            && $this->check($user, 'submit', $review);
    }

    /** The manager's half. The reviewer, or HR standing in for a manager who has left. */
    public function reviewAsManager(User $user, PerformanceReview $review): bool
    {
        if ($review->statusKey() !== 'manager_review') {
            return false;
        }

        return ($review->reviewer_id === $user->getKey() && $this->check($user, 'submit', $review))
            || $this->check($user, 'manage', $review);
    }

    /** Send it for sign-off. The same people, by the same rule — submitting IS finishing. */
    public function submitForSignOff(User $user, PerformanceReview $review): bool
    {
        return $this->reviewAsManager($user, $review);
    }

    /**
     * Say they have seen it.
     *
     * Only ever the subject, including for HR: acknowledging somebody else's review on their behalf
     * would make the one signature on the record that is supposed to be personal worthless.
     */
    public function acknowledge(User $user, PerformanceReview $review): bool
    {
        return $review->user_id === $user->getKey()
            && $review->statusKey() === 'completed'
            && ! $review->isAcknowledged()
            && $this->check($user, 'view', $review);
    }

    public function cancel(User $user, PerformanceReview $review): bool
    {
        return ! $review->isFinal() && $this->check($user, 'manage', $review);
    }

    private function subject(Model $record): Model
    {
        return $record instanceof PerformanceReviewItem
            ? ($record->review ?? $record)
            : $record;
    }
}
