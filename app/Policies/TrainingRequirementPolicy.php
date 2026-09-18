<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * What each job requires.
 *
 * SHARES THE HSE TRAINING MODULE, deliberately: the person who keeps the course catalogue is the
 * person who says which jobs need which course, and splitting the two into separate permissions
 * would mean granting both to describe one responsibility — which in practice means granting one
 * and wondering why half the screen is empty.
 *
 * The matrix itself is a READ of this register and of `training_records`, and both halves are
 * filtered by the reader's own reach. See TrainingRequirementService::matrix() (§61).
 */
class TrainingRequirementPolicy extends ScopedPolicy
{
    protected string $module = 'hse_training';
}
