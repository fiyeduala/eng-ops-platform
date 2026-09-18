<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payslip;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may read one person's pay.
 *
 * THE INTERESTING CASE IS THE EMPLOYEE THEMSELVES. A role holding `payroll.view` at scope `own`
 * reaches exactly one person's payslips — theirs — because the payslip carries its own `user_id`,
 * copied from the employee when the run was calculated. It is NOT reached through the access parent:
 * `own` resolves against columns on the record's own table and never inherits through
 * `accessParentRelation`, so a payslip without that column would be invisible to the person it
 * belongs to while looking, from the outside, exactly like a working page.
 *
 * There is still no "is this my payslip" branch anywhere in this file, because a bespoke ownership
 * test here would be a sixth gate that the index page does not run, and the register would start
 * disagreeing with the record about who may see what.
 *
 * NOTHING IS EDITABLE, EVER, AND NOT BY ANYBODY. A payslip is written by calculating a run and by
 * nothing else — there is no update, no delete, no form and no route that reaches one. A payslip
 * somebody could hand-edit would be a payslip nobody could rely on, including the person it belongs
 * to and the auditor asking what was paid (§59). Recalculating an open run replaces its payslips
 * wholesale; a locked run's payslips are replaced by nothing at all.
 *
 * A SKIPPED PAYSLIP IS STILL A PAYSLIP and is readable on the same terms. Somebody who was not paid
 * this month has the strongest possible interest in seeing the line that says why.
 */
class PayslipPolicy extends ScopedPolicy
{
    protected string $module = 'payroll';

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    /**
     * Download the PDF.
     *
     * Deliberately NOT `payroll.export`. Export is bulk — everybody's pay in one file — and a person
     * saving their own payslip is not doing that. Tying the two together would mean a company could
     * not let people keep their own payslips without also letting them take the whole payroll.
     */
    public function download(User $user, Payslip $payslip): bool
    {
        return $this->check($user, 'view', $payslip);
    }
}
