<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QualityInspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "Come and look before we pour."
 *
 * AN INSPECTED RECORD IS FROZEN, on exactly the reasoning HSE inspections use: `inspected_at` marks
 * the point where the record stops being a request and starts being testimony about what was found
 * at a hold point. Editing an acceptance criterion or flipping a fail to a pass afterwards is
 * falsifying a witness record, and on a construction project that record is what a certifying
 * authority will read.
 *
 * REQUESTING IS BROAD, INSPECTING IS NARROW. Any site engineer raises a request for inspection —
 * that is `create` and most role templates grant it widely. Recording the verdict is `approve`,
 * because the verdict is the QA/QC engineer's or the client representative's judgement, and letting
 * whoever raised the request also sign it off would make the hold point self-releasing.
 *
 * NOBODY INSPECTS THEIR OWN REQUEST. Refused at any scope, for that reason.
 *
 * A REJECTED INSPECTION IS NOT AN NCR. Rejecting records what was found; whether it becomes a formal
 * non-conformance with a disposition and a cost is a separate decision on a separate record, opened
 * from here through the same `create_record` verb an incident uses to open an investigation.
 */
class QualityInspectionPolicy extends ScopedPolicy
{
    protected string $module = 'quality_inspection';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof QualityInspection && $record->wasInspected()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof QualityInspection && $record->wasInspected()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * Attend and record the verdict.
     *
     * `approve` covers pass, pass-with-comment and reject alike: the authority being granted is
     * "you decide these", not "you say yes to these". Splitting them would let a company grant the
     * power to reject work without the power to release it, which no site actually wants.
     */
    public function inspect(User $user, QualityInspection $inspection): bool
    {
        if ($inspection->wasInspected()) {
            return false;
        }

        if ($this->requestedIt($user, $inspection)) {
            return false;
        }

        return $this->check($user, 'approve', $inspection);
    }

    private function requestedIt(User $user, QualityInspection $inspection): bool
    {
        return (int) $inspection->created_by === $user->getKey();
    }
}
