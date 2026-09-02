<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One line of a purchase request. Reach, and the freeze, are the request's.
 */
class PurchaseRequestItemPolicy extends LineItemPolicy
{
    protected string $module = 'purchase_request';

    protected function parentRelation(): string
    {
        return 'purchaseRequest';
    }
}
