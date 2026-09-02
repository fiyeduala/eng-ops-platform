<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One line of an enquiry. Reach is the enquiry's, which is what stops a supplier's contact from
 * being given a way in through a line.
 */
class RfqItemPolicy extends LineItemPolicy
{
    protected string $module = 'rfq';

    protected function parentRelation(): string
    {
        return 'rfq';
    }
}
