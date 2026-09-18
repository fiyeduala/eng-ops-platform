<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReviewCycle;
use App\Models\ReviewCycleCompetency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The appraisal round itself — and, through the same policy, the questions it asks.
 *
 * A CYCLE IS PUBLIC AND A REVIEW IS NOT. Anybody who can see the performance module can see that
 * the mid-year round is open and what it asks; `performance.view_confidential` is about what people
 * WROTE, which lives on the reviews. Splitting the two is what lets HR announce a round without
 * announcing anybody's rating (§68).
 *
 * OPENING A ROUND IS `manage`, not `create`. Creating a cycle drafts a document; opening it raises
 * a review for every person in the company and puts a task on every manager's desk, which is a
 * different size of act and deserves a different permission.
 *
 * THE COMPETENCIES ARE BOUND HERE TOO. A child route is a way in, and a questions endpoint that
 * answered to its own rules would be a second door to a cycle somebody could not otherwise reach.
 */
class ReviewCyclePolicy extends ScopedPolicy
{
    protected string $module = 'performance';

    public function view(User $user, Model $record): bool
    {
        return parent::view($user, $this->subject($record));
    }

    public function update(User $user, Model $record): bool
    {
        $cycle = $this->subject($record);

        // A closed round is a record of what was asked and answered. Editing it afterwards would
        // change the questions underneath reviews that have already been signed off.
        return $cycle instanceof ReviewCycle
            && ! $cycle->isClosed()
            && parent::update($user, $cycle);
    }

    public function delete(User $user, Model $record): bool
    {
        $cycle = $this->subject($record);

        // A cycle with reviews behind it is history, not a draft. Deleting it would take every
        // appraisal raised against it with it.
        return $cycle instanceof ReviewCycle
            && ! $cycle->reviews()->exists()
            && parent::delete($user, $cycle);
    }

    public function open(User $user, ReviewCycle $cycle): bool
    {
        return ! $cycle->isClosed() && $this->check($user, 'manage', $cycle);
    }

    public function close(User $user, ReviewCycle $cycle): bool
    {
        return $cycle->isOpen() && $this->check($user, 'manage', $cycle);
    }

    public function cancel(User $user, ReviewCycle $cycle): bool
    {
        return ! $cycle->isClosed() && $this->check($user, 'manage', $cycle);
    }

    /**
     * A competency is authorized as its cycle. See the class docblock.
     */
    private function subject(Model $record): Model
    {
        return $record instanceof ReviewCycleCompetency
            ? ($record->cycle ?? $record)
            : $record;
    }
}
