<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RiskAssessment;
use App\Models\RiskAssessmentHazard;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One hazard line on one assessment.
 *
 * Reach is entirely the parent's — a hazard row means nothing apart from the assessment it sits in,
 * and giving it a permission of its own would create the possibility of somebody reaching the
 * controls without reaching the document that explains them.
 *
 * The parent's approval freeze therefore reaches the rows too: `update` here asks the assessment's
 * `update`, which is refused once approved. That is the substance of the freeze, because editing
 * the assessment mostly MEANS editing these rows.
 */
class RiskAssessmentHazardPolicy extends ScopedPolicy
{
    protected string $module = 'hse_risk';

    public function view(User $user, Model $record): bool
    {
        return $this->throughAssessment($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughAssessment($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughAssessment($user, 'update', $record) ?? parent::delete($user, $record);
    }

    public function createFor(User $user, RiskAssessment $assessment): bool
    {
        return Gate::forUser($user)->allows('update', $assessment);
    }

    private function throughAssessment(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof RiskAssessmentHazard) {
            return null;
        }

        $assessment = $record->relationLoaded('riskAssessment')
            ? $record->riskAssessment
            : $record->riskAssessment()->first();

        return $assessment === null ? false : Gate::forUser($user)->allows($ability, $assessment);
    }
}
