<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The permit type catalogue — hot work, confined space, excavation, electrical isolation.
 *
 * Governed by the `permit` module rather than one of its own, for the reason the project type
 * catalogue is governed by `project`: reading a type is reading a label every permit page needs, and
 * editing the list is permit administration. Four more checkboxes in front of every administrator
 * would express what `permit.manage` already says (§32).
 *
 * BUT NOTE WHAT THE PRECAUTION TEMPLATE IS. This is not decoration. `precaution_template` is the
 * default checklist every permit of this type is created with, so whoever edits this record is
 * editing the safety checks that will appear on hundreds of future permits. That is why every write
 * folds onto `manage` and not onto `edit`: a permit issuer holding `permit.edit` for their day-to-day
 * work must not be able to quietly remove the gas test from the confined-space template.
 */
class PermitTypePolicy extends ScopedPolicy
{
    protected string $module = 'permit';

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

    /** A type still describing permits cannot be removed; their headings would go blank. */
    public function delete(User $user, Model $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->permits()->exists();
    }
}
