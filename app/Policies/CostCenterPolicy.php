<?php

declare(strict_types=1);

namespace App\Policies;

class CostCenterPolicy extends ScopedPolicy
{
    protected string $module = 'budget';
}
