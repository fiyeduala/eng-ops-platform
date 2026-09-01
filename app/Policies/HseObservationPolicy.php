<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HseObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "I saw something."
 *
 * THE LOWEST-FRICTION RECORD IN THE SYSTEM, ON PURPOSE. Observations only work if reporting one is
 * almost free — a company that requires an investigation-grade form to report an unguarded edge
 * gets no observations and concludes, wrongly, that its site is safe. So `create` is the permission
 * most role templates grant broadly, and the register's value is in volume.
 *
 * ANONYMITY IS STRUCTURAL, NOT COSMETIC. `observed_by` is NULL on an anonymous observation — the
 * name is not stored and hidden, it is not stored. That means the reporter genuinely cannot be
 * recovered by anybody, including an administrator with database access, and it also means an
 * anonymous observation grants its reporter nothing through `own` or `assigned` scope. They filed
 * it and let it go. That is the trade, and it is the right one: a register where the name is
 * present but suppressed is a register nobody trusts twice.
 *
 * A CLOSED OBSERVATION IS FROZEN, like everything else in the phase.
 */
class HseObservationPolicy extends ScopedPolicy
{
    protected string $module = 'hse_observation';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof HseObservation && $record->isClosedOut()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof HseObservation && $record->isClosedOut()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * Close the observation out.
     *
     * `close` rather than `edit`, because closing is the statement that the condition was dealt
     * with. In a register whose whole purpose is that people keep filing into it, letting anybody
     * who can correct a typo also declare the matter finished would empty it of meaning.
     */
    public function close(User $user, HseObservation $observation): bool
    {
        return ! $observation->isClosedOut() && $this->check($user, 'close', $observation);
    }

    /** Reopen — the only route back to editing a closed observation. */
    public function reopen(User $user, HseObservation $observation): bool
    {
        return $observation->isClosedOut() && $this->check($user, 'close', $observation);
    }
}
