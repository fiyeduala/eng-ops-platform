<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Engineering change requests (§27).
 *
 * A DECIDED CHANGE IS FROZEN. Once a disposition has been recorded, the request may not be edited or
 * deleted: the estimate, the reason and the scope are what the decision was made on, and a register
 * where they can be adjusted afterwards cannot support the claim it exists to support. If the
 * estimate turns out wrong, that is a new change request, not a rewrite of the old one.
 *
 * DECIDING IS NOT REVIEWING, AND NEITHER IS RAISING. Three abilities, deliberately separate:
 * `submit` sends it up, `review` records the technical assessment, `approve`/`reject` record the
 * commercial answer. A company that wants one person to do all three grants all three — but the
 * default templates do not, because the same person estimating a change and approving it is the
 * arrangement every audit report is written about.
 *
 * The policy refuses a self-decision outright, at any scope. That is the one rule not left to
 * configuration: an approval chain can be configured to route a change back to its author, and this
 * is the backstop that says the author still cannot be the one who signs it off.
 */
class ChangeRequestPolicy extends ScopedPolicy
{
    protected string $module = 'change_request';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof ChangeRequest && $record->isDecided()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof ChangeRequest && $record->isDecided()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    public function submit(User $user, ChangeRequest $request): bool
    {
        return ! $request->isDecided() && $this->check($user, 'submit', $request);
    }

    public function review(User $user, ChangeRequest $request): bool
    {
        return ! $request->isDecided() && $this->check($user, 'review', $request);
    }

    /**
     * File a decided change away.
     *
     * Its own ability, and it has to be: `review` is the assessment — starting it, sending it for
     * decision, handing it back to the originator — and every one of those is meaningless once a
     * decision exists, so `review` correctly refuses a decided request. Closing is the opposite
     * shape: it is only possible AFTER a decision. Routing closure through `review` would have made
     * the close button permanently dead, which is precisely what the test caught.
     *
     * The permission behind it is still `change_request.review`, because filing is administration
     * of the register rather than a new authority a company has to think about. What it is not is
     * `approve` — the person who took the decision should not have to be the one who tidies up
     * after it — and it is not `edit`, which somebody may hold merely to reword a description.
     */
    public function close(User $user, ChangeRequest $request): bool
    {
        return $request->isDecided() && $this->check($user, 'review', $request);
    }

    /**
     * Record the commercial answer — approved, rejected, deferred or withdrawn.
     *
     * `approve` covers all four because the ability being granted is "you decide these", not "you
     * say yes to these"; a separate `reject` permission would let a company grant the power to
     * refuse without the power to accept, which is not a distinction any organization actually
     * makes. The refusal path still checks `reject` where a role template distinguishes them.
     */
    public function decide(User $user, ChangeRequest $request): bool
    {
        if ($request->isDecided()) {
            return false;
        }

        // Not your own. See the class docblock — this is the one rule configuration cannot relax.
        if ($this->isAuthor($user, $request)) {
            return false;
        }

        return $this->check($user, 'approve', $request);
    }

    /**
     * Whoever put the change forward, whether they typed it or it was logged on their behalf.
     *
     * Both columns are checked because they diverge: a project engineer raises the change and a
     * coordinator enters it, or the reverse. Either of them deciding it is a self-approval.
     */
    private function isAuthor(User $user, ChangeRequest $request): bool
    {
        return (int) $request->created_by === $user->getKey()
            || (int) $request->raised_by === $user->getKey();
    }
}
