<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Field definitions (§73).
 *
 * Same module as the option lists a select field draws on, for the reason set out in
 * OptionListPolicy: whoever adds a dropdown decides what is in it.
 *
 * READING IS BASELINE, AND HAS TO BE. Every create form on every register asks this catalogue what
 * else to render; a person who could not read the definitions would be shown a form missing the
 * fields their company considers mandatory, and would then be refused on save by a rule they were
 * never shown. Reading a definition is not reading an answer — see CustomFieldValue, which has no
 * policy at all because it borrows the record's.
 */
class CustomFieldPolicy extends ScopedPolicy
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
