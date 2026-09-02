<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may say what arrived.
 *
 * A RECEIPT FREEZES THE MOMENT IT LEAVES DRAFT, and nothing lifts that — not `goods_receipt.manage`,
 * not the organization owner. The counts on a receipt are what somebody standing at a gate said
 * they saw, and by the time the receipt is confirmed the stock ledger and the invoice check are
 * both resting on them. A correction is a new receipt, with its own date and its own author, which
 * is how a paper goods-inwards book has always worked and why nobody argues with one.
 *
 * `createFor()` ASKS ABOUT THE ORDER, not just about the module. A person who may record receipts
 * must not be able to record one against an order they cannot see — that is an IDOR on a hidden
 * `purchase_order_id`, and it is how goods get booked in against somebody else's commitment (§84,
 * §94). It also refuses an order that is not issued: receiving against a draft is goods arriving on
 * a commitment nobody approved.
 *
 * THE RECEIVER-IS-NOT-THE-BUYER RULE IS NOT HERE, deliberately. It depends on
 * `procurement.separate_buyer_and_receiver` and on who raised that particular order, so
 * GoodsReceiptService enforces it and explains itself. Answering it here would tell somebody who
 * holds exactly the right permission that they do not have permission, which is both untrue and
 * the kind of message that generates a support ticket instead of a second signature.
 */
class GoodsReceiptPolicy extends ScopedPolicy
{
    protected string $module = 'goods_receipt';

    /** Draft only, for everybody. See the class docblock. */
    public function update(User $user, Model $record): bool
    {
        if ($record instanceof GoodsReceipt && ! $record->isEditable()) {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * Deleting a confirmed receipt would remove the reason a stock movement happened.
     *
     * Drafts are deletable — a receipt started against the wrong order and abandoned is not
     * evidence of anything.
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof GoodsReceipt && ! $record->isEditable()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Book a delivery in against a specific order. */
    public function createFor(User $user, PurchaseOrder $order): bool
    {
        return $this->check($user, 'create')
            && Gate::forUser($user)->allows('view', $order)
            && $order->acceptsReceipts();
    }

    /** Accept the delivery, or resolve a dispute. The second signature on the chain. */
    public function approve(User $user, GoodsReceipt $receipt): bool
    {
        return $this->check($user, 'approve', $receipt);
    }

    public function manage(User $user, GoodsReceipt $receipt): bool
    {
        return $this->check($user, 'manage', $receipt);
    }
}
