<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One supplier's invitation to one enquiry.
 *
 * Governed by `rfq`, not by `vendor`. Inviting a company to quote is an act on the ENQUIRY — the
 * vendor register is only being read — and gating it on `vendor.edit` would mean a buyer needed
 * authority to change supplier records in order to send them an email.
 */
class RfqVendorPolicy extends LineItemPolicy
{
    protected string $module = 'rfq';

    protected function parentRelation(): string
    {
        return 'rfq';
    }
}
