<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The company's joining and leaving lists. Configuration, edited in Settings by whoever holds
 * `checklist.manage`; readable by everybody who has to work through one.
 */
class ChecklistTemplatePolicy extends ScopedPolicy
{
    protected string $module = 'checklist';
}
