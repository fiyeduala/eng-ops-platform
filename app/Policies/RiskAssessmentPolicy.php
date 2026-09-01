<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The document that says how the work will be done safely.
 *
 * AN APPROVED ASSESSMENT IS FROZEN, and this is the strictest freeze in the phase. A risk
 * assessment is the document briefed to a crew before they start; if its controls can be edited
 * after approval, then what was approved and what was briefed are two different documents and
 * nobody can say which one the crew was working to. A change is a revision — a new assessment
 * superseding this one — and that is not a limitation of the register, it is how the discipline
 * works.
 *
 * NOBODY APPROVES THEIR OWN. The assessor cannot approve, at any scope. An assessment written and
 * approved by the same person has had exactly one opinion applied to it, which is the situation
 * approval exists to prevent.
 *
 * REVIEW AND APPROVAL ARE DIFFERENT ACTS. Reviewing is the competent-person check that the hazards
 * and controls make sense; approving is the manager accepting the residual risk on the company's
 * behalf. `review` maps onto `edit` reach because a reviewer is working on the document; `approve`
 * is its own permission because accepting risk is its own authority.
 *
 * EXPIRY IS A DATE, NOT A STATUS. `valid_to` past means the assessment no longer authorises
 * anything, and no policy check is needed for that — the register surfaces it, and the permit
 * module refuses to issue against an expired assessment. The policy's job is who may write; the
 * clock's job is what still applies.
 */
class RiskAssessmentPolicy extends ScopedPolicy
{
    protected string $module = 'hse_risk';

    public function update(User $user, Model $record): bool
    {
        if ($record instanceof RiskAssessment && $record->isApproved()) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof RiskAssessment && $record->isApproved()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** The competent-person check. Working on the document, so it follows `edit` reach. */
    public function review(User $user, RiskAssessment $assessment): bool
    {
        return ! $assessment->isApproved() && $this->check($user, 'update', $assessment);
    }

    /** Accept the residual risk. Never your own; see the class docblock. */
    public function approve(User $user, RiskAssessment $assessment): bool
    {
        if ($assessment->isApproved()) {
            return false;
        }

        if ($this->wroteIt($user, $assessment)) {
            return false;
        }

        return $this->check($user, 'approve', $assessment);
    }

    /**
     * Whoever produced the assessment, whether they are recorded as the assessor or entered it.
     *
     * Both columns for the same reason change requests check both: the safety officer writes it and
     * the coordinator types it in, or the reverse, and either of them approving it is a
     * self-approval.
     */
    private function wroteIt(User $user, RiskAssessment $assessment): bool
    {
        return (int) $assessment->assessed_by === $user->getKey()
            || (int) $assessment->created_by === $user->getKey();
    }
}
