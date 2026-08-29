<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\AccessResolver;
use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Audit\AuditLogger;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\Enums\AccessScope;
use App\Support\PermissionCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Roles and the permissions they carry (§5, §6, §10).
 *
 * This screen is where a company defines its own access model without a developer, so it has to be
 * both completely open and completely safe. The two are reconciled by one rule, enforced in
 * RolePolicy::editGrants and re-stated in the UI: an administrator may only put into a role
 * permissions they already hold themselves. Anything else and the entire access model collapses to
 * a single question — can you edit roles — and every bounded administrator is bounded only until
 * they open this page.
 *
 * The editor shows permissions the actor cannot grant, greyed out rather than hidden. Hiding them
 * would make the role look like it carries less than it does, which is worse than admitting the
 * limit.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly AccessResolver $resolver,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Role::class);

        $user = $request->user();

        $query = $this->compiler->apply(Role::query(), $user, 'role.view')
            ->withCount(['users', 'permissions']);

        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%'.$term.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like));
        }

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return view('roles.index', [
            'roles' => $query->orderByDesc('level')->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => [
                'q' => $request->query('q'),
                'include_inactive' => $request->boolean('include_inactive'),
            ],
            'canCreate' => $user->can('create', Role::class),
        ]);
    }

    public function show(Request $request, Role $role): View
    {
        $this->authorize('view', $role);

        $user = $request->user();
        $role->loadMissing('permissions');

        return view('roles.show', [
            'role' => $role,
            'catalogue' => $this->catalogue($user, $role->grantsMap(), grantedOnly: true),
            'scopeLabels' => $this->scopeOptions(),
            'holders' => $this->holders($user, $role),
            'holderCount' => $role->users()->count(),
            'canEdit' => $user->can('update', $role),
            'canDelete' => $user->can('delete', $role),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Role::class);

        return view('roles.create', [
            'role' => new Role(['is_active' => true, 'level' => 50]),
            'catalogue' => $this->catalogue($request->user(), []),
            'scopes' => $this->scopeOptions(),
        ]);
    }

    public function store(RoleRequest $request, RolePolicy $policy): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $actor = $request->user();
        $grants = $request->grants();

        // Checked before the role exists, so a role is never created carrying reach its author
        // could not have added afterwards.
        if (! $policy->mayGrantAll($actor, array_keys($grants))) {
            return back()->withInput()->withErrors([
                'scope' => 'You have selected permissions you do not hold yourself. Only permissions you already have can be put into a role.',
            ]);
        }

        $role = new Role;
        $role->organization_id = $actor->organization_id;   // never from input (§74)
        $role->fill($request->attributesOnly());
        $role->slug = $role->slug ?: $this->uniqueSlug($role->name);
        $role->is_system = false;                            // never from input
        $role->save();

        $role->syncGrants($grants);

        $this->audit->log(
            action: 'role_grants_changed',
            subject: $role,
            old: ['grants' => []],
            new: ['grants' => $grants],
            description: $actor->name.' created the role “'.$role->name.'”',
            actor: $actor,
        );

        $this->activity->record($role, 'created', $actor->name.' created the role “'.$role->name.'”');

        return redirect()->route('roles.show', $role)
            ->with('status', 'The role “'.$role->name.'” has been created.');
    }

    public function edit(Request $request, Role $role): View
    {
        $this->authorize('update', $role);

        $role->loadMissing('permissions');

        return view('roles.edit', [
            'role' => $role,
            'catalogue' => $this->catalogue($request->user(), $role->grantsMap()),
            'scopes' => $this->scopeOptions(),
            'holderCount' => $role->users()->count(),
        ]);
    }

    public function update(RoleRequest $request, Role $role, RolePolicy $policy): RedirectResponse
    {
        $this->authorize('update', $role);

        $actor = $request->user();
        $before = $this->normalise($role->grantsMap());
        $grants = $this->normalise($request->grants());

        // Two separate questions, both asked. `update` said the actor may touch this role at all;
        // this says they may hand out everything they are now ADDING to it.
        //
        // "Adding" is measured per scope, not per permission key. Comparing keys alone would let a
        // bounded administrator take a permission the role already carries at `own` — one they do
        // not hold themselves and therefore could never have added — and widen it to
        // `organization`. The key is unchanged, so a key-level diff sees nothing, and every holder
        // of the role silently gains company-wide reach.
        //
        // Anything that is a strict subset of what the role already carried is allowed through:
        // removing a permission, or dropping one of its two axes, is a reduction. A bounded
        // administrator must be able to narrow an over-broad role, or nobody except the owner ever
        // could.
        $added = [];

        foreach ($grants as $key => $scopes) {
            if (array_diff($scopes, $before[$key] ?? []) !== []) {
                $added[] = $key;
            }
        }

        if (! $policy->mayGrantAll($actor, $added)) {
            return back()->withInput()->withErrors([
                'scope' => 'You have added — or widened — permissions you do not hold yourself. A role can only be given reach its author already has. You may still remove any of them entirely.',
            ]);
        }

        $role->fill($request->attributesOnly());
        $role->slug = $role->slug ?: $this->uniqueSlug($role->name, $role->id);
        $role->save();

        $role->syncGrants($grants);

        // Audited explicitly. The grants live on a pivot table, so the model observer's field-level
        // diff sees nothing — and a permission change that leaves no trace is precisely the change
        // worth tracing (§59).
        if ($before !== $grants) {
            $this->audit->log(
                action: 'role_grants_changed',
                subject: $role,
                old: ['grants' => $before],
                new: ['grants' => $grants],
                description: $actor->name.' changed the permissions carried by “'.$role->name.'”',
                actor: $actor,
            );
        }

        $this->activity->record($role, 'updated', $actor->name.' updated the role “'.$role->name.'”');

        return redirect()->route('roles.show', $role)
            ->with('status', 'The role “'.$role->name.'” has been updated.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        // The policy refuses system roles and any role somebody still holds: deleting one would
        // silently strip reach from every holder, and the trail would show a permission change
        // nobody made.
        $this->authorize('delete', $role);

        $name = $role->name;

        $this->activity->record($role, 'deleted', $request->user()->name.' deleted the role “'.$name.'”');

        $role->delete();

        return redirect()->route('roles.index')
            ->with('status', 'The role “'.$name.'” has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The permission catalogue, arranged for the editor.
     *
     * Shape: group => module key => ['label' => string, 'permissions' => array<int,array{...}>]
     *
     * `grantable` says whether the ACTOR holds this permission and may therefore hand it out. It
     * drives the disabled state in the form; it is not the enforcement point — RolePolicy is.
     *
     * @param  array<string,array<int,string>>  $current  permission key => scopes the role holds
     * @return array<string,array<string,array{label:string,permissions:array<int,array<string,mixed>>}>>
     */
    private function catalogue(User $actor, array $current, bool $grantedOnly = false): array
    {
        // The catalogue is the vocabulary; the permissions table is what actually exists after the
        // last seeder run. Intersecting them keeps the editor from offering a checkbox that could
        // never be saved.
        $live = Permission::query()->pluck('key')->flip();

        $out = [];

        foreach (PermissionCatalogue::modules() as $module => [$group, $label, $actions]) {
            $rows = [];

            foreach ($actions as $action) {
                $key = $module.'.'.$action;

                if (! isset($live[$key])) {
                    continue;
                }

                $scopes = $current[$key] ?? [];

                if ($grantedOnly && $scopes === []) {
                    continue;
                }

                $organizational = collect($scopes)
                    ->first(fn (string $scope) => $scope !== AccessScope::Project->value);

                $rows[] = [
                    'key' => $key,
                    'action' => $action,
                    'label' => PermissionCatalogue::describe($label, $action),
                    'sensitive' => PermissionCatalogue::isSensitive($module, $action),
                    'scope' => $organizational,
                    'project' => in_array(AccessScope::Project->value, $scopes, true),
                    'grantable' => $this->resolver->has($actor, $key),
                ];
            }

            if ($rows === []) {
                continue;
            }

            $out[$group][$module] = ['label' => $label, 'permissions' => $rows];
        }

        return $out;
    }

    /**
     * The people holding this role, filtered through the viewer's own reach.
     *
     * A role is an organization-wide definition, but the list of who holds it is a list of people —
     * so it obeys `user.view` scope like every other people list. Someone who can read role
     * definitions does not thereby get a staff directory (§61).
     */
    private function holders(User $actor, Role $role): Collection
    {
        if (! $actor->hasPermission('user.view')) {
            return collect();
        }

        return $this->compiler->apply(User::query(), $actor, 'user.view')
            ->whereHas('roles', fn ($q) => $q->whereKey($role->id))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email', 'is_active']);
    }

    /** @return array<string,string> */
    private function scopeOptions(): array
    {
        $out = ['' => 'Not granted'];

        foreach (AccessScope::cases() as $scope) {
            if ($scope->isOrganizational()) {
                $out[$scope->value] = $scope->label();
            }
        }

        return $out;
    }

    private function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $n = 2;

        while (Role::withTrashed()
            ->where('slug', $slug)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->exists()
        ) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Sorted, so an audit diff reflects a real change rather than checkbox ordering.
     *
     * @param  array<string,array<int,string>>  $grants
     * @return array<string,array<int,string>>
     */
    private function normalise(array $grants): array
    {
        ksort($grants);

        foreach ($grants as $key => $scopes) {
            $scopes = array_values(array_unique($scopes));
            sort($scopes);
            $grants[$key] = $scopes;
        }

        return $grants;
    }
}
