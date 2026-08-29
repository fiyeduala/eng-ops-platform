<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The configurable status vocabulary (§63).
 *
 * Reading is baseline — the words "In progress" and "Blocked" appear on every list in the
 * application, so `status.view` sits in the RoleTemplates baseline and reaches every row of the
 * tenant. Writing is `status.manage`, and it is genuinely administrative: renaming a status
 * changes what a hundred existing records say they are.
 */
class StatusPolicy extends ScopedPolicy
{
    protected string $module = 'status';

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

    /**
     * A status in use is deactivated, never deleted.
     *
     * Deleting it would leave every record sitting in it with a dangling `status_id`, and the
     * transitions naming it would quietly stop working. `is_active` exists so a company can
     * retire a status without rewriting its own history.
     */
    public function delete(User $user, $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $this->isInUse($record);
    }

    /**
     * Is any record currently sitting in this status?
     *
     * Asked of the table the entity_type names — the module key, so the lookup survives a class
     * being renamed. A module that has not shipped yet has no table and therefore no records.
     */
    protected function isInUse(Status $status): bool
    {
        $table = Str::plural((string) $status->entity_type);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status_id')) {
            return false;
        }

        return DB::table($table)->where('status_id', $status->getKey())->exists();
    }
}
