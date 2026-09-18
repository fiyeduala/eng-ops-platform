<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may open, calculate, send and export a payroll run.
 *
 * THERE IS NO `approve` HERE, and there is no approve route. A run is decided by acting on its
 * workflow stage — WorkflowStageInstancePolicy's question, the same one it answers for a drawing and
 * a leave request. `payroll.approve` stays in the catalogue so an administrator can name it in a
 * route's role, and grants nothing on its own. Payroll is the place where a private approve button
 * would be most tempting and most dangerous, so it gets exactly the same door as everything else.
 *
 * `run` IS SEPARATE FROM `edit`. Naming a period and calculating a payroll are different acts by
 * different people: a payroll clerk opens and calculates, a finance manager decides. Somebody who
 * may correct a typo in a run's name has not thereby been given the authority to produce the
 * figures the company will pay against.
 *
 * NOTHING CHANGES ONCE APPROVED. `locked_at` closes update, delete and calculate to everybody,
 * administrator included, because an approved run is the record of what was actually paid and is
 * relied on months later by people who were not in the room (§59). A mistake is corrected by a new
 * run pointing back at this one, which is also how a correction works in the real world.
 */
class PayrollRunPolicy extends ScopedPolicy
{
    protected string $module = 'payroll';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof PayrollRun && $record->isEditable() && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        // A locked run cannot be deleted either — soft deletion would take it off the register while
        // leaving the money paid, which is the worst of both.
        return $record instanceof PayrollRun && $record->isEditable() && parent::delete($user, $record);
    }

    /** Work the figures out. Repeatable while the run is still the payroll clerk's to change. */
    public function calculate(User $user, PayrollRun $run): bool
    {
        return $run->isEditable() && $this->check($user, 'run', $run);
    }

    /**
     * Send it for approval.
     *
     * Only from `calculated`, never from `draft`. Approving a run with no payslips in it would be an
     * approval of nothing, given in good faith, which somebody would later point at.
     */
    public function submit(User $user, PayrollRun $run): bool
    {
        return $run->statusKey() === 'calculated' && $this->check($user, 'run', $run);
    }

    /**
     * Take the whole run out of the system — the bank file, the journal, the register.
     *
     * Its own permission because an export is a copy of every salary in the company in a file that
     * leaves the platform's access control behind the moment it is saved (§67). Somebody who may
     * read the register on screen has not been given that.
     */
    public function export(User $user, PayrollRun $run): bool
    {
        return $this->check($user, 'export', $run);
    }

    /** Open a correcting run against one that is already locked. */
    public function correct(User $user, PayrollRun $run): bool
    {
        return $run->isLocked() && $this->check($user, 'create', $run);
    }

    public function cancel(User $user, PayrollRun $run): bool
    {
        return ! $run->isLocked()
            && ! in_array($run->statusKey(), ['rejected', 'cancelled'], true)
            && $this->check($user, 'run', $run);
    }
}
