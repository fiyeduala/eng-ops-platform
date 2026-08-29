<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Models\User;
use App\Support\Enums\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * Everything the scope compiler needs to know about WHERE a user sits.
 *
 * Resolved once per user per request and memoized, because a single page can compile scopes for
 * a dozen different permissions and each would otherwise re-walk the org tree.
 */
class ActorProfile
{
    /** @var array<int,array<string,mixed>> */
    private array $cache = [];

    /**
     * The user's own org unit id, from their employee record.
     */
    public function orgUnitId(User $user): ?int
    {
        return $this->profile($user)['org_unit_id'];
    }

    public function employeeId(User $user): ?int
    {
        return $this->profile($user)['employee_id'];
    }

    /**
     * Ids of every org unit inside the subtree the given scope reaches.
     *
     * `department` walks UP from the user's own unit to the nearest ancestor of type
     * "department", then returns that ancestor's whole subtree. A user sitting in a team inside
     * a section therefore still gets department-wide reach when granted it.
     *
     * Returns null when the user has no position in the tree, which means "reaches nothing".
     *
     * @return array<int,int>|null
     */
    public function subtreeFor(User $user, AccessScope $scope): ?array
    {
        $type = $scope->orgUnitType();

        if ($type === null) {
            return null;
        }

        $profile = $this->profile($user);
        $anchorId = $profile['ancestors_by_type'][$type->value] ?? null;

        if ($anchorId === null) {
            return null;
        }

        return $this->subtreeOf($anchorId);
    }

    /**
     * Ids of a unit and all of its descendants, using the materialized path.
     *
     * @return array<int,int>
     */
    public function subtreeOf(int $orgUnitId): array
    {
        $key = 'subtree:'.$orgUnitId;

        if (isset($this->cache[0][$key])) {
            return $this->cache[0][$key];
        }

        $path = DB::table('org_units')->where('id', $orgUnitId)->value('path');

        if ($path === null) {
            return $this->cache[0][$key] = [$orgUnitId];
        }

        $ids = DB::table('org_units')
            ->where('path', 'like', $path.'%')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->cache[0][$key] = $ids ?: [$orgUnitId];
    }

    /**
     * Projects the user is an ACTIVE member of, right now.
     *
     * Membership is time-bounded (section 12), so a consultant assigned for three months loses
     * project-axis visibility automatically when ends_on passes — no manual revocation, and
     * historical audit entries stay intact. See docs/05-project-visibility.md section 5.
     *
     * @return array<int,int>
     */
    public function projectIds(User $user): array
    {
        return $this->profile($user)['project_ids'];
    }

    /**
     * Employee ids directly or transitively reporting to this user, for supervisor-based reach.
     *
     * @return array<int,int>
     */
    public function subordinateEmployeeIds(User $user, int $maxDepth = 10): array
    {
        $employeeId = $this->employeeId($user);

        if ($employeeId === null) {
            return [];
        }

        $key = 'subordinates:'.$employeeId;

        if (isset($this->cache[0][$key])) {
            return $this->cache[0][$key];
        }

        $collected = [];
        $frontier = [$employeeId];

        for ($depth = 0; $depth < $maxDepth && $frontier !== []; $depth++) {
            $next = DB::table('employees')
                ->whereIn('supervisor_id', $frontier)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => in_array($id, $collected, true))
                ->all();

            $collected = array_merge($collected, $next);
            $frontier = $next;
        }

        return $this->cache[0][$key] = $collected;
    }

    /**
     * @return array{employee_id:?int,org_unit_id:?int,ancestors_by_type:array<string,int>,project_ids:array<int,int>}
     */
    private function profile(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $employee = DB::table('employees')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first(['id', 'org_unit_id']);

        $ancestorsByType = [];
        $orgUnitId = $employee?->org_unit_id ? (int) $employee->org_unit_id : null;

        if ($orgUnitId !== null) {
            $path = DB::table('org_units')->where('id', $orgUnitId)->value('path');

            // "/1/7/23/" -> [1, 7, 23]; the user's own unit is included so a person sitting
            // directly in a department still resolves the `department` anchor.
            $ancestorIds = array_values(array_filter(array_map(
                'intval',
                explode('/', (string) $path)
            )));

            if ($ancestorIds !== []) {
                $units = DB::table('org_units')
                    ->whereIn('id', $ancestorIds)
                    ->get(['id', 'type']);

                foreach ($units as $unit) {
                    // Nearest ancestor of each type wins; deeper ids appear later in the path.
                    $ancestorsByType[$unit->type] = (int) $unit->id;
                }
            }
        }

        return $this->cache[$user->id] = [
            'employee_id' => $employee?->id ? (int) $employee->id : null,
            'org_unit_id' => $orgUnitId,
            'ancestors_by_type' => $ancestorsByType,
            'project_ids' => $this->loadProjectIds($user),
        ];
    }

    /** @return array<int,int> */
    private function loadProjectIds(User $user): array
    {
        // project_members does not exist until Phase 2; degrade gracefully so Phase 1 boots.
        if (! $this->tableExists('project_members')) {
            return [];
        }

        $today = now()->toDateString();

        return DB::table('project_members')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function tableExists(string $table): bool
    {
        $key = 'table:'.$table;

        return $this->cache[0][$key] ??= DB::getSchemaBuilder()->hasTable($table);
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
