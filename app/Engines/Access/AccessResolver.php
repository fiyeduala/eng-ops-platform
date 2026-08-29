<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Models\User;
use App\Support\Enums\AccessScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gates 2 and 3: does the user hold this permission, and how far does it reach?
 *
 * Effective permissions are the UNION of grants across every active role assignment:
 *
 *   effective(user) = U over active assignments r:
 *                      U over grants g in r.role.permissions:
 *                        [ g.permission.key => ScopeGrant(g.scope, pinned by r.scope) ]
 *
 * The broadest scope wins when the same permission arrives from several roles; a role
 * assignment's own scope_type/scope_id NARROWS every grant it carries.
 *
 * Results are cached under a key containing the organization's permissions_version counter, so
 * any change to roles, grants, assignments or structure invalidates every affected user at once
 * without cache scanning. See docs/06-rbac-matrix.md section 6.
 */
class AccessResolver
{
    /** @var array<string,array<string,ScopeSet>> in-request memo, keyed by cache key */
    private array $memo = [];

    private const CACHE_TTL = 3600;

    /**
     * Every permission the user holds, keyed by permission key.
     *
     * @return array<string,ScopeSet>
     */
    public function permissions(User $user): array
    {
        $key = $this->cacheKey($user);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $raw = Cache::remember($key, self::CACHE_TTL, fn () => $this->buildRaw($user));

        $sets = [];
        foreach ($raw as $permission => $grants) {
            $sets[$permission] = ScopeSet::fromArray($grants);
        }

        return $this->memo[$key] = $sets;
    }

    /**
     * The reach the user has for one permission. Empty means "not held at all".
     */
    public function scopesFor(User $user, string $permission): ScopeSet
    {
        return $this->permissions($user)[$permission] ?? ScopeSet::empty();
    }

    /**
     * Gate 2 only: does the user hold this permission at any scope?
     *
     * This answers "may this KIND of person do this?" — never "may they do it to THIS record".
     * Record-level questions must go through a policy.
     */
    public function has(User $user, string $permission): bool
    {
        return $this->scopesFor($user, $permission)->isNotEmpty();
    }

    /** True if the user holds any of the given permissions. */
    public function hasAny(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->has($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAll(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->has($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does the user hold this permission organization-wide (unpinned)?
     * Used for cheap short-circuits in the compiler and for executive dashboards.
     */
    public function hasOrganizationWide(User $user, string $permission): bool
    {
        return $this->scopesFor($user, $permission)->hasOrganizationWide();
    }

    /**
     * Build the raw, cacheable grant map.
     *
     * One query, no N+1, no Eloquent hydration — this runs on every request.
     *
     * @return array<string,array<int,array>>
     */
    private function buildRaw(User $user): array
    {
        // The organization owner implicitly holds every permission organization-wide.
        //
        // This is applied HERE, not as a Gate::before short-circuit, and that distinction is the
        // whole point: an owner still passes through Gate 1 (they cannot reach another tenant),
        // Gate 4 (an explicit deny on a record still denies them) and Gate 5. "Super admin" means
        // unlimited breadth inside one company — it never means unlimited reach across companies.
        if ($user->is_super_admin) {
            $all = [];

            foreach (DB::table('permissions')->pluck('key') as $key) {
                $all[$key] = [(new ScopeGrant(AccessScope::Organization))->toArray()];
            }

            return $all;
        }

        $today = now()->toDateString();

        $rows = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.organization_id', $user->organization_id)
            ->where('roles.is_active', true)
            ->whereNull('roles.deleted_at')
            ->where(fn ($q) => $q->whereNull('role_user.starts_at')->orWhere('role_user.starts_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('role_user.ends_at')->orWhere('role_user.ends_at', '>=', $today))
            ->get([
                'permissions.key as permission_key',
                'permission_role.scope as scope',
                'role_user.scope_type as pin_type',
                'role_user.scope_id as pin_id',
            ]);

        $sets = [];

        foreach ($rows as $row) {
            $scope = AccessScope::tryFrom($row->scope);

            if ($scope === null) {
                continue;
            }

            $grant = new ScopeGrant(
                scope: $scope,
                pinnedOrgUnitId: $row->pin_type === 'org_unit' ? (int) $row->pin_id : null,
                pinnedProjectId: $row->pin_type === 'project' ? (int) $row->pin_id : null,
            );

            $sets[$row->permission_key] ??= ScopeSet::empty();
            $sets[$row->permission_key]->add($grant);
        }

        return array_map(fn (ScopeSet $set) => $set->toArray(), $sets);
    }

    private function cacheKey(User $user): string
    {
        $version = $user->organization?->permissions_version ?? 0;

        return sprintf('perm:%d:%d:v%d', $user->organization_id ?? 0, $user->id, $version);
    }

    /** Drop the in-request memo. Cross-request invalidation happens via permissions_version. */
    public function flushMemo(): void
    {
        $this->memo = [];
    }

    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
        unset($this->memo[$this->cacheKey($user)]);
    }
}
