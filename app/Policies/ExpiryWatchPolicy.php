<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * What the platform watches for a company (§73).
 *
 * READING IS BASELINE, for the same reason it is on the custom fields. A watch is the answer to
 * "why was I told about this?" and "why was nobody told about that?", and somebody who could not
 * read the list has no way to answer either — they would be left believing the platform watches
 * something it does not, which is worse than knowing it watches nothing.
 *
 * MANAGING IS NOT. Turning a watch off is the quietest destructive act available in the
 * configuration screens: nothing breaks, no screen changes, no record moves, and the company simply
 * stops being warned that its permits are lapsing. There is no error to notice and no page that
 * looks wrong — the first symptom is an expired certificate somebody was standing on. That is why
 * it sits behind `manage` rather than `edit`, and why the model is audited.
 */
class ExpiryWatchPolicy extends ScopedPolicy
{
    protected string $module = 'expiry_watch';

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
