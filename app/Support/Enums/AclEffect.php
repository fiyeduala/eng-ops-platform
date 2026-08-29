<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum AclEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
