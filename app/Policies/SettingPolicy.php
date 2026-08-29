<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

/**
 * Configuration values (§5, Engine 5).
 *
 * `setting.view` and `setting.manage` are the only two abilities: a setting is either something
 * you can read or something you can change, and there is no useful middle.
 */
class SettingPolicy extends ScopedPolicy
{
    protected string $module = 'setting';

    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'delete_permanently',
    ];

    /**
     * A setting flagged is_editable = false is platform-enforced structure, not a company
     * preference — the repository refuses to write it, and this stops the UI offering to.
     */
    public function update(User $user, $record): bool
    {
        if ($record instanceof Setting && ! $record->is_editable) {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * Settings are created and removed by the provisioner, not by hand. An administrator changes
     * values; adding new keys would mean the application reading a setting that no code consumes.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, $record): bool
    {
        return false;
    }
}
