<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Models\User;
use App\Support\Enums\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "Does this person see EVERYTHING in that scope?" — the question a shared figure has to pass.
 *
 * WHY THIS EXISTS. Phase 7 established that a KPI holds no number of its own: every figure is
 * counted now, through the visibility of whoever asked, because a total belongs to whoever it was
 * counted for. That rule is right and it does not scale. A command center with twenty tiles cannot
 * run twenty scoped aggregates on every page load (§95), and a trend line is worthless if last
 * quarter's point moves every time somebody edits a record (docs/11 §5).
 *
 * So Phase 8 stores figures — and this class is the entire reason storing them is safe. A stored
 * figure is stamped with the SCOPE it was counted over, never with a viewer, and it is handed to
 * somebody only when their reach contains that whole scope. If it does not, they get their own live
 * count or an honest blank. Nobody ever reads a number derived from rows they could not open.
 *
 * WHAT "CONTAINS" HAS TO MEAN. Holding a permission organization-wide is necessary and NOT
 * sufficient, and this is the part that would have been easy to get wrong. Two things carve holes
 * out of an organization-wide grant, both of them invisible at the permission level:
 *
 *   - CONFIDENTIALITY. `ScopeCompiler::applyConfidentiality()` drops restricted and confidential
 *     rows from any register carrying that column unless the viewer holds the module's
 *     `view_confidential` permission. A department head with organization-wide `incident.view` and
 *     no `incident.view_confidential` sees fewer incidents than the company has. Handing them the
 *     company snapshot would tell them the count of records they were specifically not shown.
 *   - EXPLICIT DENIALS. A single `record_permissions` deny row withholds one record from one person
 *     regardless of scope (Gate 4a). One such row anywhere in the register is enough to make a
 *     shared figure wrong for that person — and wrong in the direction that matters, since the gap
 *     between the shared figure and their own list is exactly the record somebody hid from them.
 *
 * Both checks are per person per register, not per row: one permission lookup and one EXISTS. That
 * is what makes this affordable to ask before every tile.
 *
 * THIS CLASS NEVER WIDENS ANYTHING. It returns a verdict about a figure; it fetches no records and
 * is not consulted on authorization. Reach for authorization runs downward through ScopeCompiler as
 * it always has, and a `covers` verdict here is a licence to show a CACHED number, not a licence to
 * see a row. Getting it wrong shows somebody a total that is too big — bad, and bounded. It can
 * never open a record.
 */
final class ReachInspector
{
    /** @var array<string,array<string,int>> table => column => 1 */
    private array $columnCache = [];

    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly ActorProfile $profile,
    ) {}

    /**
     * Does this person see every row of this register the company holds?
     *
     * @param  Model  $prototype  an unsaved instance of the register, for its table and morph alias
     */
    public function coversOrganization(User $user, Model $prototype, string $permission): ReachVerdict
    {
        if (! $this->resolver->hasOrganizationWide($user, $permission)) {
            return ReachVerdict::partial('You see part of this register rather than all of it, so this is counted for you.');
        }

        return $this->holes($user, $prototype, $permission);
    }

    /**
     * Does this person see every row belonging to this unit and everything beneath it?
     *
     * Containment is set containment, deliberately, rather than a comparison of scope ranks. A
     * division head and a department head can both hold `department`, and which unit that reaches
     * depends on where each of them sits — so the only honest test is whether the units this
     * person's grants actually reach include the entire subtree being asked about.
     */
    public function coversOrgUnit(User $user, Model $prototype, string $permission, int $orgUnitId): ReachVerdict
    {
        if (! $this->resolver->hasOrganizationWide($user, $permission)
            && ! $this->reachesWholeSubtree($user, $permission, $orgUnitId)) {
            return ReachVerdict::partial('You see part of this department rather than all of it, so this is counted for you.');
        }

        return $this->holes($user, $prototype, $permission);
    }

    /**
     * Does this person see every row belonging to this project?
     *
     * A project grant is all-or-nothing by construction — `ScopeCompiler::applyProject()` matches on
     * project membership and does not subdivide — so belonging to the project is containment. What
     * it is NOT is a lift on confidentiality: docs/04 §7 is explicit that project membership never
     * raises clearance, which is why the hole checks below still run.
     */
    public function coversProject(User $user, Model $prototype, string $permission, int $projectId): ReachVerdict
    {
        if (! $this->resolver->hasOrganizationWide($user, $permission)
            && ! $this->reachesWholeProject($user, $permission, $projectId)) {
            return ReachVerdict::partial('You see part of this project rather than all of it, so this is counted for you.');
        }

        return $this->holes($user, $prototype, $permission);
    }

    // ================================================================== the two holes

    /**
     * The checks that apply however broad the grant was.
     */
    private function holes(User $user, Model $prototype, string $permission): ReachVerdict
    {
        if ($this->hasColumn($prototype, 'confidentiality')) {
            $module = str_contains($permission, '.') ? explode('.', $permission)[0] : $permission;

            if (! $this->resolver->has($user, $module.'.view_confidential')) {
                return ReachVerdict::partial(
                    'Some records here are confidential and are not counted for you, so this is your own figure.'
                );
            }
        }

        if ($this->hasDenial($user, $prototype, $permission)) {
            return ReachVerdict::partial(
                'Some individual records here have been withheld from you, so this is your own figure.'
            );
        }

        return ReachVerdict::covers();
    }

    /**
     * Is there a live deny row that could withhold any record of this register from this person?
     *
     * Asked without touching the register itself: one indexed lookup against `record_permissions`,
     * the same grantee shapes `ScopeCompiler::aclSubquery()` matches, and the same ability-or-
     * wildcard rule — a deny on `document.approve` must not suppress a count of documents viewed.
     *
     * Deliberately pessimistic. It asks whether such a row EXISTS, not whether it hides a record
     * inside the scope being counted, because answering the narrower question means joining to the
     * register and that is the aggregate this whole class exists to avoid running. The cost of
     * being pessimistic is that one person falls back to their own live figure; the cost of being
     * optimistic is that they read a total including a record somebody deliberately hid from them.
     */
    private function hasDenial(User $user, Model $prototype, string $permission): bool
    {
        $roleIds = $user->roleIds();
        $unitId = $this->profile->orgUnitId($user);

        return DB::table('record_permissions')
            ->where('organization_id', $user->organization_id)
            ->where('record_type', $prototype->getMorphClass())
            ->where('effect', 'deny')
            ->whereIn('ability', [$permission, '*'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) use ($user, $roleIds, $unitId) {
                $q->where(function ($w) use ($user) {
                    $w->where('grantee_type', 'user')->where('grantee_id', $user->getKey());
                });

                if ($roleIds !== []) {
                    $q->orWhere(function ($w) use ($roleIds) {
                        $w->where('grantee_type', 'role')->whereIn('grantee_id', $roleIds);
                    });
                }

                if ($unitId !== null) {
                    $q->orWhere(function ($w) use ($unitId) {
                        $w->where('grantee_type', 'org_unit')->where('grantee_id', $unitId);
                    });
                }
            })
            ->exists();
    }

    // ================================================================== reach arithmetic

    /**
     * Do this person's grants for this permission reach every unit in that subtree?
     */
    private function reachesWholeSubtree(User $user, string $permission, int $orgUnitId): bool
    {
        $wanted = array_map('intval', $this->profile->subtreeOf($orgUnitId));

        if ($wanted === []) {
            return false;
        }

        foreach ($this->resolver->scopesFor($user, $permission)->effective() as $grant) {
            if (! $grant->scope->isOrganizational() || $grant->scope->orgUnitType() === null) {
                continue;
            }

            $reached = $this->profile->subtreeFor($user, $grant->scope);

            if ($reached === null || $reached === []) {
                continue;
            }

            $reached = array_map('intval', $reached);

            // A pinned grant is narrowed to the pinned subtree, exactly as ScopeCompiler::applyGrant
            // narrows it. Reading the pin as a widening would be the expensive mistake here.
            if ($grant->pinnedOrgUnitId !== null) {
                $reached = array_values(array_intersect(
                    $reached,
                    array_map('intval', $this->profile->subtreeOf($grant->pinnedOrgUnitId))
                ));
            }

            // A grant pinned to a project constrains records by project as well as by unit, so it
            // cannot on its own cover a whole department's rows.
            if ($grant->pinnedProjectId !== null) {
                continue;
            }

            if (array_diff($wanted, $reached) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Do this person's grants reach the whole of that one project?
     *
     * A `project` grant pinned to a different project reaches nothing here, and a grant pinned to a
     * unit subtree is narrower than the project, so neither counts as containment.
     */
    private function reachesWholeProject(User $user, string $permission, int $projectId): bool
    {
        if (! in_array($projectId, array_map('intval', $this->profile->projectIds($user)), true)) {
            return false;
        }

        foreach ($this->resolver->scopesFor($user, $permission)->effective() as $grant) {
            if ($grant->scope !== AccessScope::Project) {
                continue;
            }

            if ($grant->pinnedOrgUnitId !== null) {
                continue;
            }

            if ($grant->pinnedProjectId !== null && (int) $grant->pinnedProjectId !== $projectId) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function hasColumn(Model $prototype, string $column): bool
    {
        $table = $prototype->getTable();

        if (! isset($this->columnCache[$table])) {
            $this->columnCache[$table] = array_flip(
                $prototype->getConnection()->getSchemaBuilder()->getColumnListing($table)
            );
        }

        return isset($this->columnCache[$table][$column]);
    }
}
