<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One counted line.
 *
 * The delegation matters more here than anywhere else in the chain: GoodsReceipt refuses `update`
 * once the receipt leaves `draft`, so these counts freeze with it. A correction is a new receipt,
 * never an edit to what somebody said they saw (§59).
 */
class GoodsReceiptItemPolicy extends LineItemPolicy
{
    protected string $module = 'goods_receipt';

    protected function parentRelation(): string
    {
        return 'goodsReceipt';
    }
}
