<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DisciplinaryCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Conduct cases — the tightest policy in the platform, and the one whose defaults matter most.
 *
 * INVESTIGATING AND DECIDING ARE DIFFERENT PERMISSIONS, held by different people on purpose. The
 * person who gathers the facts should not also be the person who hands down the sanction; keeping
 * them apart is the difference between a process and a verdict. Nothing stops a company granting
 * both to one person — that is their call to make — but it has to be a decision somebody took.
 *
 * THE SUBJECT CANNOT READ THEIR OWN CASE HERE. That reads harshly and it is deliberate: an open
 * allegation with witness statements in it is not a record the subject browses in an app while the
 * investigation runs. What they are entitled to — the allegation, the hearing, the outcome — reaches
 * them through the process, on paper, at the time the process says. Note the absence of an `own`
 * path below; the model has no `user_id` column for exactly this reason.
 *
 * AN INVESTIGATOR READS ONE CASE. Assignment writes a Gate 4 row for that record alone
 * (DisciplinaryService::assignInvestigator), which is how somebody outside HR works a case without
 * being able to read anybody else's.
 *
 * A CLOSED CASE IS IMMUTABLE. Not editable, not deletable, by anybody. It is what the company will
 * point at if it ends up in front of a tribunal, and a register whose history can be tidied
 * afterwards is worth nothing there (§59).
 */
class DisciplinaryCasePolicy extends ScopedPolicy
{
    protected string $module = 'disciplinary';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof DisciplinaryCase
            && ! $record->isClosedCase()
            && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof DisciplinaryCase
            && ! $record->isClosedCase()
            && ! $record->isDecided()
            && parent::delete($user, $record);
    }

    public function assignInvestigator(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && $this->check($user, 'manage', $case);
    }

    /** Gather and record the facts. The assigned investigator, or anyone who may investigate. */
    public function investigate(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && $this->check($user, 'investigate', $case);
    }

    public function scheduleHearing(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && ! $case->isDecided() && $this->check($user, 'investigate', $case);
    }

    public function decide(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && $this->check($user, 'decide', $case);
    }

    /** The appeal is decided by whoever decides cases — and, in practice, by somebody senior. */
    public function decideAppeal(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && $case->isDecided() && $this->check($user, 'decide', $case);
    }

    public function close(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && $case->isDecided() && $this->check($user, 'decide', $case);
    }

    public function withdraw(User $user, DisciplinaryCase $case): bool
    {
        return ! $case->isClosedCase() && $this->check($user, 'manage', $case);
    }
}
