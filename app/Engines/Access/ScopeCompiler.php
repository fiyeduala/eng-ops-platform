<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Models\Organization;
use App\Models\User;
use App\Support\Enums\AccessScope;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a ScopeSet into SQL.
 *
 * This is why index pages, exports and global search cannot leak: filtering happens in the
 * DATABASE, as one OR-group of predicates, rather than by fetching rows and rejecting them in
 * PHP. A developer who forgets a check does not get a leak — they get a query that returns
 * nothing, because the compiler fails closed.
 */
class ScopeCompiler
{
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly ActorProfile $profile,
    ) {}

    /**
     * Constrain a query to the records the user may reach under the given permission.
     *
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, User $user, string $permission): Builder
    {
        // Gate 1, restated against the ACTOR rather than the ambient context.
        //
        // The tenant global scope already filters by whatever organization is in context, and in
        // a web request EnsureOrganizationContext sets that from the authenticated user, so the
        // two always agree. This line is what makes that a convenience rather than the only thing
        // standing between two tenants: a queued job, a console command or any future code that
        // sets context explicitly cannot hand user A a query that reaches organization B.
        //
        // It also makes the compiler agree with ScopedPolicy, which has always checked the record
        // against the user's own organization_id — without it, a list could contain rows the
        // record page would refuse, which leaks existence. See docs/04-access-model.md section 6.
        $this->restrictToActorOrganization($query, $user);

        // Gate 0, in SQL. ScopedPolicy asks the same question before anything else, but index
        // pages call visibleTo() directly and never touch a policy — so a suspended tenant would
        // still get a fully populated list on every listing screen if this were only in the
        // policy. Same answer on both paths, or the list and the record page disagree.
        if (! $user->canLogIn() || ! $this->resolver->tenantIsActive($user)) {
            return $query->whereRaw('1 = 0');
        }

        $set = $this->resolver->scopesFor($user, $permission);

        // Gate 2 failed: the permission is not held at any scope. Fail closed.
        if ($set->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        // Gate 4a, in SQL. Applied to every path including organization-wide reach, because a
        // deny that the CEO's breadth could out-vote would not be a deny. This mirrors
        // ScopedPolicy::check() exactly, so a denied record is absent from the list AND from the
        // record page — never present in one and refused by the other.
        $this->excludeDenied($query, $user, $permission);

        // Fast path: organization-wide reach needs no predicate beyond the tenant global scope.
        //
        // The second condition covers organization-level reference data — sites, role
        // definitions — where the model declares that narrowing this particular ability selects
        // nothing rather than a subset. See Scopeable::accessOrganizationWideAbilities().
        if ($set->hasOrganizationWide() || $this->isOrganizationWideAbility($query->getModel(), $permission)) {
            return $this->finalise($query, $user, $permission);
        }

        $grants = $set->effective();

        $query->where(function (Builder $group) use ($grants, $user, $permission) {
            foreach ($grants as $grant) {
                $group->orWhere(function (Builder $clause) use ($grant, $user) {
                    $this->applyGrant($clause, $grant, $user);
                });
            }

            // Gate 4b, in SQL: a record shared directly with this user is reachable even when no
            // organizational or project scope would have reached it.
            $group->orWhereExists(function ($sub) use ($group, $user, $permission) {
                $this->aclSubquery($sub, $group->getModel(), $user, $permission, 'allow');
            });
        });

        return $this->finalise($query, $user, $permission);
    }

    /**
     * The two filters that apply to EVERY path, including organization-wide reach.
     *
     * Extracted so the fast path and the normal path cannot drift apart. They ran as one line for
     * as long as there was only confidentiality; the moment a second such filter existed, having
     * two call sites became a place for one of them to be forgotten — and a filter that is missing
     * on the organization-wide path is missing for exactly the people whose reach is widest.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function finalise(Builder $query, User $user, string $permission): Builder
    {
        $this->applyParentProtection($query, $user);

        return $this->applyPublication(
            $this->applyConfidentiality($query, $user, $permission),
            $user,
            $permission,
        );
    }

    /** Parent protection does not require a second module permission: drivers can read their
     * assigned vehicle without being granted the asset register. It cannot be lifted by a child ACL.
     */
    private function applyParentProtection(Builder $query, User $user): void
    {
        $relation = $this->parentRelation($query->getModel());

        if ($relation === null) {
            return;
        }

        $query->whereHas($relation, function (Builder $parent) use ($user) {
            $permission = $parent->getModel()::defaultViewPermission();
            $this->restrictToActorOrganization($parent, $user);
            $this->excludeDenied($parent, $user, $permission);
            $this->finalise($parent, $user, $permission);
        });
    }

    private function parentRelation(Model $model): ?string
    {
        return method_exists($model, 'accessParentRelation') ? $model->accessParentRelation() : null;
    }

    /**
     * Draft gating, for the models that declare a publication column.
     *
     * Confidentiality answers "how sensitive is this"; publication answers "is this finished".
     * They are independent — an ordinary internal memo can be a draft, and a confidential one can
     * be issued — so this is a separate predicate rather than another branch of Gate 5.
     *
     * A row survives if ANY of four things is true:
     *
     *   1. it is published — the column is set;
     *   2. it is the actor's own draft — an author must be able to find what they were writing;
     *   3. it has been shared with the actor explicitly, which is the same ACL escape Gates 4b
     *      and 5 honour, and the only reason a co-author can be given a draft at all in V1;
     *   4. the actor holds the publication ability, i.e. they are one of the people who decides
     *      that drafts become communications.
     *
     * Note what is NOT on that list: organizational scope, project membership, and breadth. The
     * managing director does not read half-written memos, and that is the feature.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyPublication(Builder $query, User $user, string $permission): Builder
    {
        $model = $query->getModel();

        $column = method_exists($model, 'accessPublicationColumn')
            ? $model->accessPublicationColumn()
            : null;

        if ($column === null || ! $this->hasColumn($model, $column)) {
            return $query;
        }

        $ability = method_exists($model, 'accessPublicationAbility')
            ? $model->accessPublicationAbility()
            : null;

        if ($ability !== null && $this->resolver->has($user, $ability)) {
            return $query;
        }

        $ownerColumns = $this->ownerColumns($model);

        return $query->where(function (Builder $group) use ($model, $column, $ownerColumns, $user, $permission) {
            $group->whereNotNull($model->qualifyColumn($column));

            foreach ($ownerColumns as $ownerColumn) {
                $group->orWhere($model->qualifyColumn($ownerColumn), $user->id);
            }

            $group->orWhereExists(function ($sub) use ($model, $user, $permission) {
                $this->aclSubquery($sub, $model, $user, $permission, 'allow');
            });
        });
    }

    /**
     * Pin the query to the actor's own tenant.
     *
     * A user with no organization_id reaches nothing at all — the only accounts in that state are
     * broken ones, and failing closed is the right answer for a broken account.
     *
     * @param  Builder<Model>  $query
     */
    private function restrictToActorOrganization(Builder $query, User $user): void
    {
        $model = $query->getModel();

        // The Organization model has no organization_id; its own key plays that role.
        $column = $model instanceof Organization
            ? $model->getKeyName()
            : 'organization_id';

        if (! $this->hasColumn($model, $column)) {
            return;   // genuinely global reference data (permission catalogue, countries)
        }

        $query->where($model->qualifyColumn($column), $user->organization_id ?? 0);
    }

    /**
     * NOT EXISTS an unexpired deny row for this user, role or unit.
     *
     * @param  Builder<Model>  $query
     */
    private function excludeDenied(Builder $query, User $user, string $permission): void
    {
        $model = $query->getModel();

        $query->whereNotExists(function ($sub) use ($model, $user, $permission) {
            $this->aclSubquery($sub, $model, $user, $permission, 'deny');
        });
    }

    /**
     * One grant becomes one bracketed clause: the scope predicate, ANDed with its pin.
     *
     * @param  Builder<Model>  $query
     */
    private function applyGrant(Builder $query, ScopeGrant $grant, User $user): void
    {
        $model = $query->getModel();

        $this->applyScopePredicate($query, $grant->scope, $user, $model);

        // A pinned assignment narrows the grant to one unit subtree or one project.
        if ($grant->pinnedOrgUnitId !== null) {
            $this->applyInheritedPin($query, 'unit', $this->profile->subtreeOf($grant->pinnedOrgUnitId));
        }

        if ($grant->pinnedProjectId !== null) {
            $this->applyInheritedPin($query, 'project', [$grant->pinnedProjectId]);
        }
    }

    /** Apply assignment bounds through the same ownership hierarchy as the grant itself. */
    private function applyInheritedPin(Builder $query, string $axis, array $ids): void
    {
        $model = $query->getModel();
        $column = $axis === 'unit' ? $this->orgUnitColumn($model) : $this->projectColumn($model);

        if ($column !== null) {
            $query->whereIn($model->qualifyColumn($column), $ids);
        } elseif (($relation = $this->parentRelation($model)) !== null) {
            $query->whereHas($relation, fn (Builder $parent) => $this->applyInheritedPin($parent, $axis, $ids));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyScopePredicate(Builder $query, AccessScope $scope, User $user, Model $model): void
    {
        $inherits = match ($scope) {
            AccessScope::Project => $this->projectColumn($model) === null,
            AccessScope::Team, AccessScope::Section, AccessScope::Department, AccessScope::Division => $this->orgUnitColumn($model) === null,
            default => false,
        };

        if ($inherits && ($relation = $this->parentRelation($model)) !== null) {
            $query->whereHas($relation, function (Builder $parent) use ($scope, $user) {
                $this->applyScopePredicate($parent, $scope, $user, $parent->getModel());
            });

            return;
        }

        match ($scope) {
            AccessScope::Organization => $query->whereRaw('1 = 1'),

            AccessScope::Own => $this->applyOwn($query, $user, $model),

            // `assigned` is a superset of `own`: a record you created is also yours to see.
            AccessScope::Assigned => $query->where(function (Builder $inner) use ($user, $model) {
                $inner->where(fn (Builder $q) => $this->applyOwn($q, $user, $model));
                $this->applyAssigned($inner, $user, $model);
            }),

            AccessScope::Project => $this->applyProject($query, $user, $model),

            AccessScope::Team,
            AccessScope::Section,
            AccessScope::Department,
            AccessScope::Division => $this->applyUnitSubtree($query, $scope, $user, $model),
        };
    }

    /** @param Builder<Model> $query */
    private function applyOwn(Builder $query, User $user, Model $model): void
    {
        $columns = $this->ownerColumns($model);

        if ($columns === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $inner) use ($columns, $user, $model) {
            foreach ($columns as $column) {
                $inner->orWhere($model->qualifyColumn($column), $user->id);
            }
        });
    }

    /** @param Builder<Model> $query */
    private function applyAssigned(Builder $query, User $user, Model $model): void
    {
        // Models describe their own notion of "assigned to me" — assignee, reviewer,
        // participant, follower. Defaults live in the Scopeable trait.
        if (method_exists($model, 'applyAssignedScope')) {
            $model->applyAssignedScope($query, $user);

            return;
        }

        foreach ($this->assigneeColumns($model) as $column) {
            $query->orWhere($model->qualifyColumn($column), $user->id);
        }
    }

    /** @param Builder<Model> $query */
    private function applyProject(Builder $query, User $user, Model $model): void
    {
        $column = $this->projectColumn($model);
        $projectIds = $this->profile->projectIds($user);

        if ($column === null || $projectIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($model->qualifyColumn($column), $projectIds);
    }

    /** @param Builder<Model> $query */
    private function applyUnitSubtree(Builder $query, AccessScope $scope, User $user, Model $model): void
    {
        $column = $this->orgUnitColumn($model);
        $unitIds = $this->profile->subtreeFor($user, $scope);

        if ($column === null || $unitIds === null || $unitIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($model->qualifyColumn($column), $unitIds);
    }

    /**
     * Gate 5, applied in SQL.
     *
     * Restricted records are reachable only from the owning unit subtree or an explicit ACL
     * grant; confidential records only via ACL or a view_confidential permission. Project
     * membership never lifts either. See docs/04-access-model.md section 7.
     *
     * @param  Builder<Model>  $query
     */
    private function applyConfidentiality(Builder $query, User $user, string $permission): Builder
    {
        $model = $query->getModel();

        if (! $this->hasColumn($model, 'confidentiality')) {
            return $query;
        }

        $module = str_contains($permission, '.') ? explode('.', $permission)[0] : $permission;
        $seesConfidential = $this->resolver->has($user, $module.'.view_confidential');

        if ($seesConfidential) {
            return $query;
        }

        $unitColumn = $this->orgUnitColumn($model);
        $ownUnitSubtree = null;

        if ($unitColumn !== null || $this->parentRelation($model) !== null) {
            $ownUnitId = $this->profile->orgUnitId($user);
            $ownUnitSubtree = $ownUnitId !== null ? $this->profile->subtreeOf($ownUnitId) : null;
        }

        return $query->where(function (Builder $group) use ($model, $ownUnitSubtree, $user, $permission) {
            $column = $model->qualifyColumn('confidentiality');

            // Public and internal follow the ordinary scope rules already applied above.
            $group->whereIn($column, [
                Confidentiality::Public->value,
                Confidentiality::Internal->value,
            ]);

            // Restricted: visible from inside the owning unit subtree.
            if ($ownUnitSubtree !== null && $ownUnitSubtree !== []) {
                $group->orWhere(function (Builder $inner) use ($column, $ownUnitSubtree) {
                    $inner->where($column, Confidentiality::Restricted->value);
                    $this->applyInheritedPin($inner, 'unit', $ownUnitSubtree);
                });
            }

            // Restricted or confidential: visible via an explicit, unexpired ACL allow row.
            $group->orWhere(function (Builder $inner) use ($column, $model, $user, $permission) {
                $inner->whereIn($column, [
                    Confidentiality::Restricted->value,
                    Confidentiality::Confidential->value,
                ])->whereExists(function ($sub) use ($model, $user, $permission) {
                    $this->aclSubquery($sub, $model, $user, $permission, 'allow');
                });
            });
        });
    }

    /**
     * EXISTS subquery matching an unexpired ACL row of the given effect for this user — by user
     * id, by any role they hold, or by their org unit — for this exact ability or the wildcard.
     *
     * The ability match is not optional: without it, being shared a document for viewing would
     * silently also lift confidentiality on approving it.
     */
    private function aclSubquery($sub, Model $model, User $user, string $permission, string $effect): void
    {
        $roleIds = $user->roleIds();
        $unitId = $this->profile->orgUnitId($user);

        $sub->selectRaw('1')
            ->from('record_permissions')
            ->whereColumn('record_permissions.record_id', $model->qualifyColumn($model->getKeyName()))
            ->where('record_permissions.record_type', $model->getMorphClass())
            ->where('record_permissions.effect', $effect)
            ->whereIn('record_permissions.ability', [$permission, '*'])
            ->where(function ($q) {
                $q->whereNull('record_permissions.expires_at')
                    ->orWhere('record_permissions.expires_at', '>', now());
            })
            ->where(function ($q) use ($user, $roleIds, $unitId) {
                $q->where(function ($w) use ($user) {
                    $w->where('record_permissions.grantee_type', 'user')
                        ->where('record_permissions.grantee_id', $user->id);
                });

                if ($roleIds !== []) {
                    $q->orWhere(function ($w) use ($roleIds) {
                        $w->where('record_permissions.grantee_type', 'role')
                            ->whereIn('record_permissions.grantee_id', $roleIds);
                    });
                }

                if ($unitId !== null) {
                    $q->orWhere(function ($w) use ($unitId) {
                        $w->where('record_permissions.grantee_type', 'org_unit')
                            ->where('record_permissions.grantee_id', $unitId);
                    });
                }
            });
    }

    // ---------------------------------------------------------------- model introspection

    /** @return array<int,string> */
    private function ownerColumns(Model $model): array
    {
        $declared = method_exists($model, 'accessOwnerColumns')
            ? $model->accessOwnerColumns()
            : ['created_by', 'owner_id', 'user_id'];

        return array_values(array_filter(
            $declared,
            fn (string $column) => $this->hasColumn($model, $column)
        ));
    }

    /** @return array<int,string> */
    private function assigneeColumns(Model $model): array
    {
        $declared = method_exists($model, 'accessAssigneeColumns')
            ? $model->accessAssigneeColumns()
            : ['assignee_id', 'assigned_to', 'responsible_id'];

        return array_values(array_filter(
            $declared,
            fn (string $column) => $this->hasColumn($model, $column)
        ));
    }

    private function isOrganizationWideAbility(Model $model, string $permission): bool
    {
        return method_exists($model, 'accessOrganizationWideAbilities')
            && in_array($permission, $model->accessOrganizationWideAbilities(), true);
    }

    private function orgUnitColumn(Model $model): ?string
    {
        $column = method_exists($model, 'accessOrgUnitColumn')
            ? $model->accessOrgUnitColumn()
            : 'org_unit_id';

        return $column !== null && $this->hasColumn($model, $column) ? $column : null;
    }

    private function projectColumn(Model $model): ?string
    {
        $column = method_exists($model, 'accessProjectColumn')
            ? $model->accessProjectColumn()
            : 'project_id';

        return $column !== null && $this->hasColumn($model, $column) ? $column : null;
    }

    /** @var array<string,array<string,bool>> */
    private array $columnCache = [];

    private function hasColumn(Model $model, string $column): bool
    {
        // A nested whereHas may alias a repeated table as laravel_reserved_N. Inspect the
        // physical table while continuing to qualify SQL with the query model's alias.
        $class = $model::class;
        $table = (new $class)->getTable();

        if (! isset($this->columnCache[$table])) {
            $this->columnCache[$table] = array_flip(
                $model->getConnection()->getSchemaBuilder()->getColumnListing($table)
            );
        }

        return isset($this->columnCache[$table][$column]);
    }
}
