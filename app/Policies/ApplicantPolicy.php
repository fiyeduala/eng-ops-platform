<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Applications, and the CV attached to them.
 *
 * DOWNLOADING IS `view`, RE-CHECKED AGAINST THE RECORD. A CV is the most sensitive thing this
 * module holds — an address, a date of birth, an employment history — so the download ability is
 * not a separate permission somebody might grant loosely. It is `view` on THIS applicant, run
 * through all five gates, and the applicant's restricted classification means project membership
 * alone never reaches it (§67, §68).
 *
 * HIRING IS ITS OWN PERMISSION. Scheduling interviews and writing somebody into the personnel
 * register are different jobs, and a recruiter who can do the first is not automatically trusted
 * with the second.
 */
class ApplicantPolicy extends ScopedPolicy
{
    protected string $module = 'recruitment';

    /** A hired or closed application is a record of what happened, not a form. */
    public function update(User $user, Model $record): bool
    {
        return $record instanceof Applicant && ! $record->isHired() && parent::update($user, $record);
    }

    /** Reading the CV is reading the applicant. See the class docblock. */
    public function download(User $user, Applicant $applicant): bool
    {
        return $applicant->hasCv() && $this->view($user, $applicant);
    }

    public function attachCv(User $user, Applicant $applicant): bool
    {
        return ! $applicant->isHired() && $this->check($user, 'update', $applicant);
    }

    /** Adding or assessing an interview step. */
    public function recordStage(User $user, Applicant $applicant): bool
    {
        return ! $applicant->isClosed() && $this->check($user, 'update', $applicant);
    }

    public function decide(User $user, Applicant $applicant): bool
    {
        return ! $applicant->isClosed() && $this->manage($user, $applicant);
    }

    public function hire(User $user, Applicant $applicant): bool
    {
        return ! $applicant->isHired()
            && $applicant->statusKey() === 'offered'
            && $this->check($user, 'hire', $applicant);
    }

    public function manage(User $user, Applicant $applicant): bool
    {
        return $this->check($user, 'manage', $applicant);
    }
}
