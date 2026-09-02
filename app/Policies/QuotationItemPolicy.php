<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One priced line. Reach is the quotation's, and the quotation defaults to confidential, so this
 * inherits the narrowest reach in the module without restating it.
 */
class QuotationItemPolicy extends LineItemPolicy
{
    protected string $module = 'quotation';

    protected function parentRelation(): string
    {
        return 'quotation';
    }
}
