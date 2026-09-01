<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HseInspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The site walk.
 *
 * A PERFORMED INSPECTION IS FROZEN, not merely a closed one, and that is the difference between
 * this and most registers in the phase. The moment `performed_at` is stamped, the record states
 * what an inspector observed at a particular time and place. Editing it afterwards — adjusting a
 * failed item to a pass, softening a comment — is falsifying an observation, and the fact that the
 * audit engine would record the change is not a substitute for refusing it.
 *
 * What remains possible after performance is exactly what should: raising corrective actions
 * against the failures, and closing the inspection once they are dealt with.
 *
 * SCHEDULING IS ORDINARY EDITING. Before it is performed, an inspection is a plan — move it, change
 * the inspector, add checklist items — and `edit` covers all of it.
 */
class HseInspectionPolicy extends ScopedPolicy
{
    protected string $module = 'hse_inspection';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof HseInspection && $record->wasPerformed()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof HseInspection && $record->wasPerformed()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * Record the results of the walk.
     *
     * `edit` reach on the record, which is what makes `assigned` scope behave: the inspector the
     * inspection belongs to performs it, and does not perform anybody else's.
     */
    public function perform(User $user, HseInspection $inspection): bool
    {
        return ! $inspection->wasPerformed() && $this->check($user, 'update', $inspection);
    }

    /** File it away once the findings have been dealt with. */
    public function close(User $user, HseInspection $inspection): bool
    {
        return $inspection->wasPerformed()
            && $inspection->closed_at === null
            && $this->check($user, 'close', $inspection);
    }
}
