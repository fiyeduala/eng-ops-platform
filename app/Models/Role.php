<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Support\Enums\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named bundle of (permission, scope) grants. Fully configurable per organization (section 5).
 *
 * `level` is a display/ordering hint only. It is NEVER a security control — a role does not
 * gain access by having a high level.
 */
class Role extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Any change to a role or its grants must invalidate cached permission sets.
        static::saved(fn (self $role) => $role->organization?->bumpPermissionsVersion());
        static::deleted(fn (self $role) => $role->organization?->bumpPermissionsVersion());
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withPivot('scope')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['scope_type', 'scope_id', 'starts_at', 'ends_at', 'assigned_by'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * Roles are organization-wide definitions with no unit, project, owner or assignee. Managing
     * them is `role.edit`, which is a sensitive administrative permission — so reach here is
     * deliberately all-or-nothing at organization scope, and every narrower grant sees nothing.
     * A role that could be edited from inside one department would be a privilege-escalation
     * path: its grants apply everywhere the role is assigned.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * Reading role NAMES is organization-level — a manager looking at a team member's profile has
     * to be able to render "Site Administrator". `role.edit`, `role.create` and `role.delete` are
     * deliberately absent: they stay bound to true organization reach, because a role edited from
     * inside one department changes access for everyone holding that role anywhere.
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['role.view'];
    }

    /**
     * Replace this role's grants.
     *
     * Written against the pivot table directly rather than via sync(). A BelongsToMany sync is
     * keyed by the related id, so it can hold exactly one row per permission — but a role is
     * allowed to grant one permission at BOTH a project scope and an organizational scope, and
     * the resolver unions them (docs/04-access-model.md §8). The unique key on permission_role is
     * (role, permission, scope) precisely to allow that pair; sync() could not express it.
     *
     * @param  array<string,string|AccessScope|array<int,string|AccessScope>>  $grants
     *                                                                                 permission key => one scope, or a list of scopes
     */
    public function syncGrants(array $grants): void
    {
        $permissionIds = Permission::whereIn('key', array_keys($grants))->pluck('id', 'key');
        $now = now();
        $rows = [];

        foreach ($grants as $key => $scopes) {
            if (! isset($permissionIds[$key])) {
                continue;   // a pattern matched a permission that no longer exists in the catalogue
            }

            foreach (Arr::wrap($scopes) as $scope) {
                $scope = $scope instanceof AccessScope ? $scope : AccessScope::from($scope);

                $rows[] = [
                    'role_id' => $this->id,
                    'permission_id' => $permissionIds[$key],
                    'scope' => $scope->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows) {
            DB::table('permission_role')->where('role_id', $this->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('permission_role')->insert($chunk);
            }
        });

        $this->load('permissions');
        $this->organization?->bumpPermissionsVersion();
    }

    /**
     * @return array<string,array<int,string>> permission key => scopes held
     */
    public function grantsMap(): array
    {
        $map = [];

        foreach ($this->permissions as $permission) {
            $map[$permission->key][] = $permission->pivot->scope;
        }

        return $map;
    }
}
