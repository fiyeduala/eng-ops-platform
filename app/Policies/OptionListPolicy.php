<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Company-defined option lists (§63).
 *
 * GATED ON `custom_field.*` RATHER THAN A MODULE OF ITS OWN, and that is a judgement worth stating.
 * An option list exists to be the contents of a dropdown field; the person who adds "Cost code" to
 * the project register is the same person who decides which cost codes there are, and splitting
 * the two would produce an administrator who can create a select field and then cannot fill it.
 * A separate permission would be a new door to the same room.
 *
 * Reading is baseline for the same reason statuses are: the labels appear on every record that
 * carries a custom field, and a dropdown that renders differently depending on who is looking is a
 * data-integrity problem wearing a permission's clothes.
 */
class OptionListPolicy extends ScopedPolicy
{
    protected string $module = 'custom_field';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'delete_permanently',
    ];
}
