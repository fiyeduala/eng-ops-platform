<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CorrectiveAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What we are going to do about it.
 *
 * ONE REGISTER, MANY SOURCES. Actions arrive from incidents, observations, inspections, audits and
 * NCRs through a polymorphic `source`, and the reason they all land here rather than in five
 * per-module lists is that "what is outstanding against me" has to be one question with one answer
 * (§90). The policy therefore governs the action itself and never consults the source: reaching an
 * action is about the action's project, unit and assignee, because an action assigned to a site
 * supervisor must be visible to that supervisor whether it came out of an incident they are not
 * cleared to read or an inspection they never attended.
 *
 * That is a deliberate disclosure boundary, and it is why the service copies only the action's own
 * title and description across when spawning. The action says what to do. It does not repeat what
 * happened (§68).
 *
 * NOBODY VERIFIES THEIR OWN. The policy refuses the assignee and the person who marked it complete
 * at any scope — the same separation of duties change requests use for approval. An action closed
 * out by the person who performed it is a self-certification, and effectiveness ratings produced
 * that way are the reason "corrective actions" appear in so many repeat-incident reports.
 *
 * A VERIFIED ACTION IS FROZEN. Verification is the record that somebody independently checked the
 * thing was actually done; editing the action afterwards would change what was verified.
 */
class CorrectiveActionPolicy extends ScopedPolicy
{
    protected string $module = 'corrective_action';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof CorrectiveAction && $record->isVerified()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof CorrectiveAction && ($record->isVerified() || $record->isComplete())) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * Hand the action to somebody.
     *
     * Its own ability because reassignment is a management act, not a correction: the person doing
     * the work may update their own action's notes and due-date discussion all day without being
     * able to push it onto a colleague.
     */
    public function assign(User $user, CorrectiveAction $action): bool
    {
        return ! $action->isVerified() && $this->check($user, 'assign', $action);
    }

    /**
     * Report the action done.
     *
     * `edit` reach on the record, which is what makes `assigned` scope work as it should: the
     * person the action belongs to can complete it and nobody else's.
     */
    public function complete(User $user, CorrectiveAction $action): bool
    {
        return ! $action->isComplete()
            && ! $action->isVerified()
            && $this->check($user, 'update', $action);
    }

    /**
     * Confirm independently that it was done, and say whether it worked.
     *
     * Only after completion — there is nothing to verify before then — and never by the assignee or
     * the completer. See the class docblock; this is the rule configuration cannot relax.
     */
    public function verify(User $user, CorrectiveAction $action): bool
    {
        if (! $action->isComplete() || $action->isVerified()) {
            return false;
        }

        if ($this->performedIt($user, $action)) {
            return false;
        }

        return $this->check($user, 'verify', $action);
    }

    /** File it away. Available once verified, or to whoever runs the register if it was withdrawn. */
    public function close(User $user, CorrectiveAction $action): bool
    {
        return $this->check($user, 'close', $action);
    }

    /**
     * Did this person do the work being verified?
     *
     * Both columns, because they diverge legitimately: an action is assigned to a supervisor and
     * marked complete by the coordinator who watched it happen. Either of them verifying it is a
     * self-certification.
     */
    private function performedIt(User $user, CorrectiveAction $action): bool
    {
        return (int) $action->assigned_to === $user->getKey()
            || (int) $action->completed_by === $user->getKey();
    }
}
