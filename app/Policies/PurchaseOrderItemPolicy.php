<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One line of a commitment. Editing it is editing the order, which is why it delegates `update`
 * rather than holding a permission of its own — the order's status decides whether anything on it
 * may still change.
 */
class PurchaseOrderItemPolicy extends LineItemPolicy
{
    protected string $module = 'purchase_order';

    protected function parentRelation(): string
    {
        return 'purchaseOrder';
    }
}
