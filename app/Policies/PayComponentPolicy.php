<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may describe how the company pays people.
 *
 * THE PAY STRUCTURE IS CONFIGURATION, NOT PAY. A component says "PAYE is a banded charge on taxable
 * pay"; it contains nobody's salary and nobody's figure. That is why it sits under `payroll.edit`
 * rather than behind `payroll.view_confidential`: an administrator setting up the company's tax
 * tables has no business reading the payroll, and requiring the confidential permission to do the
 * setup would force every company to hand out the stronger one.
 *
 * A COMPONENT THAT HAS BEEN USED IS NEVER DELETED. Every payslip line copies the code, name, kind,
 * method, basis and rate it was calculated under, so a historical payslip survives the component
 * being retired — but the component itself is what the register joins to when somebody asks which
 * runs a levy applied to. Retiring is `is_active = false`; deletion is for a row typed by mistake
 * and never used.
 */
class PayComponentPolicy extends ScopedPolicy
{
    protected string $module = 'payroll';

    /**
     * Delete only a component nothing has been calculated against.
     *
     * The check is on assignments and payslip lines rather than on age, because a component created
     * last year and never used is genuinely a mistake, and one created this morning and already on
     * fifty payslips genuinely is not.
     */
    public function delete(User $user, Model $record): bool
    {
        if (! $record instanceof PayComponent) {
            return false;
        }

        if ($record->payslipLines()->exists()) {
            return false;
        }

        return parent::delete($user, $record);
    }
}
