<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/**
 * Who may read a contract, and — separately — who may read its numbers.
 *
 * A CONTRACT DEFAULTS TO CONFIDENTIAL, which is unusual in this application and deliberate. The
 * value is the one figure that is commercially damaging in the hands of the wrong colleague: the
 * subcontractor whose price it names is frequently somebody's former employer, and a rate a
 * competitor would pay for is one screenshot away. Gate 5 in ScopedPolicy therefore does most of
 * the work here — a confidential record needs unit reach or an explicit ACL row, and that is the
 * gate a company adjusts rather than this class.
 *
 * `viewFinancials()` IS A SECOND QUESTION LAYERED ON `view`, in the same shape as
 * IncidentPersonPolicy::viewMedical(): being entitled to know that a contract exists, who it is
 * with and when it expires is not the same as being entitled to read what it is worth. Site
 * management routinely needs the first and routinely must not have the second. It reuses
 * `project.view_financials` rather than inventing a parallel permission, so a company configures
 * "who sees money" once (§32).
 *
 * `approve()` is here so a contract can be routed through Engine 2 like anything else. It does NOT
 * imply edit: the whole point of an approval is that the approver did not write what they signed.
 */
class ContractPolicy extends ScopedPolicy
{
    protected string $module = 'contract';

    /**
     * Read the value, the retention and the payment terms.
     *
     * `view` first and separately — a financial permission is not a way to reach a contract you
     * otherwise could not, so it is an AND rather than an override.
     */
    public function viewFinancials(User $user, Contract $contract): bool
    {
        return $this->check($user, 'view', $contract)
            && ($this->check($user, 'view_confidential', $contract)
                || $this->resolver->has($user, 'project.view_financials'));
    }

    /**
     * The same question asked of the register rather than of one row.
     *
     * A list page needs to know whether to render a Value column at all, and asking
     * viewFinancials() with an unsaved model would answer "no" for a different reason than the one
     * intended: Gate 3 re-runs the reach query against a null key and finds nothing. Financial
     * visibility is a PERMISSION, not a property of a particular contract, so the class-level form
     * is the honest one — it stops after Gate 2, where the answer actually lives.
     *
     * Each row is still filtered by `view` before it reaches the page, so this widens nothing.
     */
    public function viewAnyFinancials(User $user): bool
    {
        return $this->check($user, 'viewAny')
            && ($this->check($user, 'view_confidential')
                || $this->resolver->has($user, 'project.view_financials'));
    }

    /**
     * Move retention, reopen a completed contract, record execution.
     *
     * `contract.manage` rather than `contract.edit`, because these change what the commercial
     * reports said last month. Editing a description does not.
     */
    public function manage(User $user, Contract $contract): bool
    {
        return $this->check($user, 'manage', $contract);
    }

    public function approve(User $user, Contract $contract): bool
    {
        return $this->check($user, 'approve', $contract);
    }
}
