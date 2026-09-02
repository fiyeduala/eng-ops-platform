<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may commit the company's money.
 *
 * AN ISSUED ORDER IS NOT EDITABLE BY ANYBODY, `manage` included. This is the strictest freeze in
 * the platform and the easiest to argue for: the supplier holds a document, they are acting on it,
 * and our copy saying something different is not a correction — it is two versions of a contract.
 * An issued order is changed by cancelling it and raising another, or by a variation, both of which
 * leave a record the supplier can be shown.
 *
 * BEFORE ISSUE, THE FREEZE IS THE APPROVAL'S. A draft is the buyer's to change; once submitted it
 * belongs to the approver, for the same reason a submitted purchase request does — otherwise the
 * quantities can move between the reading and the signature.
 *
 * `approve()` AND `issue()` ARE DIFFERENT PERMISSIONS held by different people in most companies.
 * Approving is authority over money; issuing is contact with the supplier. A finance director
 * approves and never sends anything; a buyer sends and cannot approve their own.
 *
 * THE BUYER-IS-NOT-THE-RECEIVER RULE IS NOT HERE. It is about goods receipts, depends on a
 * per-organization setting, and lives in GoodsReceiptService where it can explain itself.
 */
class PurchaseOrderPolicy extends ScopedPolicy
{
    protected string $module = 'purchase_order';

    /**
     * Draft: the buyer's. Submitted or approved: `manage` only. Issued: nobody.
     */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof PurchaseOrder) {
            return parent::update($user, $record);
        }

        if ($record->isIssued()) {
            return false;
        }

        return $record->statusKey() === 'draft'
            ? parent::update($user, $record)
            : $this->check($user, 'manage', $record);
    }

    /**
     * An issued order is never deleted either, and neither is one anybody has received against.
     *
     * A deleted order with receipts pointing at it leaves stock movements whose reason has
     * vanished, which is the shape of every inventory discrepancy nobody can explain.
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof PurchaseOrder && $record->isIssued()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    public function submit(User $user, PurchaseOrder $order): bool
    {
        return $this->check($user, 'submit', $order);
    }

    public function approve(User $user, PurchaseOrder $order): bool
    {
        return $this->check($user, 'approve', $order);
    }

    public function reject(User $user, PurchaseOrder $order): bool
    {
        return $this->check($user, 'reject', $order);
    }

    /** Send it to the supplier. The moment the company is committed. */
    public function issue(User $user, PurchaseOrder $order): bool
    {
        return $this->check($user, 'issue', $order);
    }

    /** Cancel it, close it short, amend it before issue. */
    public function manage(User $user, PurchaseOrder $order): bool
    {
        return $this->check($user, 'manage', $order);
    }
}
