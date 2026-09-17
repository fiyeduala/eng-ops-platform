<?php

declare(strict_types=1);

namespace App\Policies;

class TaxCodePolicy extends ScopedPolicy
{
    protected string $module = 'tax_code';
}
