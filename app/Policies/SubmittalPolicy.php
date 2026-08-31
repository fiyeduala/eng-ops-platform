<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Submittal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Submittals (§27).
 *
 * A REVIEWED SUBMITTAL IS FROZEN. The review code is the client's answer; editing the package after
 * it arrived would mean the thing that was coded is no longer the thing on file. When a coded
 * submittal needs to change, the answer is a RESUBMITTAL — a new record pointing back at this one —
 * which is both how the contract works and the only version that leaves the history intact.
 *
 * REVIEWING IS NOT APPROVING. `submittal.review` records the verdict that came back, including a
 * rejection; `submittal.approve` is for organizations that review internally before issue. A company
 * that does not review internally simply never grants the second, and nothing breaks — which is the
 * test any configurable module has to pass.
 */
class SubmittalPolicy extends ScopedPolicy
{
    protected string $module = 'submittal';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof Submittal && $record->isReviewed()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Submittal && $record->isReviewed()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    public function submit(User $user, Submittal $submittal): bool
    {
        return ! $submittal->isReviewed() && $this->check($user, 'submit', $submittal);
    }

    /** Record the verdict that came back, whatever the verdict is. */
    public function review(User $user, Submittal $submittal): bool
    {
        return ! $submittal->isReviewed() && $this->check($user, 'review', $submittal);
    }

    /**
     * Raise the next attempt against a coded submittal.
     *
     * Requires `create` — a resubmittal IS a new submittal — plus reach over the one being replaced,
     * so somebody cannot chain a new record onto a package they were never allowed to see and
     * inherit its number and history.
     */
    public function resubmit(User $user, Submittal $submittal): bool
    {
        return $submittal->isReviewed()
            && $this->check($user, 'create')
            && $this->check($user, 'view', $submittal);
    }
}
