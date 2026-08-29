<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\PasswordResetService;
use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Audit\AuditLogger;
use App\Http\Requests\UserRequest;
use App\Http\Requests\UserRolesRequest;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login accounts (§4, §6, §10).
 *
 * This is the most dangerous screen in the application, because everything reachable from it is a
 * privilege-escalation surface. Three rules are enforced here that no amount of role configuration
 * can switch off:
 *
 *   1. Nobody edits their own roles. The check is on the ACTOR, so an administrator cannot widen
 *      their own reach; somebody else with the authority has to, which is what makes the audit
 *      entry mean anything (UserPolicy::assignRoles).
 *
 *   2. Nobody grants a permission they do not themselves hold. Without that rule `role.assign` is
 *      not one permission — it is every permission, one assignment away (RolePolicy::mayGrantAll).
 *
 *   3. Nobody chooses anyone else's password. Creating an account issues a reset link; the holder
 *      sets their own. An administrator who typed it would know it.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $query = $this->compiler->apply(User::query(), $actor, 'user.view')
            ->with(['employee:id,user_id,first_name,last_name,display_name,position_id', 'employee.position:id,title'])
            ->withCount('roles');

        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%'.$term.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('username', 'like', $like));
        }

        $status = (string) $request->query('status', 'active');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'disabled') {
            $query->where('is_active', false);
        } elseif ($status === 'locked') {
            $query->whereNotNull('locked_until')->where('locked_until', '>', now());
        }

        return view('users.index', [
            'users' => $query->orderBy('name')->paginate(25)->withQueryString(),
            'filters' => ['q' => $request->query('q'), 'status' => $status],
            'statuses' => [
                'active' => 'Active accounts',
                'disabled' => 'Disabled accounts',
                'locked' => 'Locked out',
                'all' => 'Every account',
            ],
            'canCreate' => $actor->can('create', User::class),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('view', $user);

        $actor = $request->user();

        $user->loadMissing(['employee.position', 'employee.orgUnit', 'roles']);

        return view('users.show', [
            'account' => $user,
            'roles' => $user->activeRoles()->get(),
            'scopeLabels' => $this->scopeLabels($user),
            'canEdit' => $actor->can('update', $user),
            'canDelete' => $actor->can('delete', $user),
            'canAssignRoles' => $actor->can('assignRoles', $user),
            'canResetPassword' => $actor->can('resetPassword', $user),
            // The audit trail for an account is a sensitive read in its own right (§59): it shows
            // every sign-in, lockout and permission change. It is shown only to holders of
            // audit_log.view, never as a side effect of being able to open the account page.
            'trail' => $actor->hasPermission('audit_log.view')
                ? $this->audit->trailFor($user)->limit(15)->get()
                : null,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('users.create', [
            'account' => new User([
                'is_active' => true,
                'timezone' => $request->user()->organization?->timezone,
                'locale' => $request->user()->organization?->locale,
            ]),
        ]);
    }

    public function store(UserRequest $request, PasswordResetService $passwords): RedirectResponse
    {
        $this->authorize('create', User::class);

        $actor = $request->user();

        $account = new User($request->validated());
        $account->organization_id = $actor->organization_id;   // never from input (§74)

        // A 64-character random string nobody ever sees. The account is unusable until the holder
        // redeems the emailed link, so there is no window in which a default password works (§92).
        $account->password = Str::random(64);
        $account->must_change_password = false;
        $account->password_changed_at = null;
        $account->save();

        $passwords->issueFor($account, $request->ip(), $actor);

        $this->activity->record($account, 'created', $actor->name.' created an account for '.$account->name);

        return redirect()->route('users.show', $account)
            ->with('status', $account->name.' has been invited. A link to set their password has been emailed to '.$account->email.'.');
    }

    public function edit(Request $request, User $user, RolePolicy $roles): View
    {
        $this->authorize('update', $user);

        $actor = $request->user();

        // The roles panel is rendered on this page, but only for someone who may actually change
        // it — and `assignRoles` is refused against yourself, so an administrator opening their own
        // edit form sees their account details and no grants panel at all.
        $canAssignRoles = $actor->can('assignRoles', $user);

        return view('users.edit', [
            'account' => $user,
            'canAssignRoles' => $canAssignRoles,
            'assignable' => $canAssignRoles ? $this->assignableRoles($actor, $roles) : collect(),
            'units' => $canAssignRoles ? $this->unitOptions($actor) : [],
            'assignments' => $canAssignRoles ? $this->currentAssignments($user) : [],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        // Disabling yourself would lock you out mid-session and, worse, is the move of an
        // administrator trying to end their own audit trail. UserPolicy already refuses `delete`
        // against self; this closes the same door on the edit form.
        if ((int) $user->id === (int) $request->user()->id) {
            unset($data['is_active']);
        }

        $user->fill($data)->save();

        $this->activity->record($user, 'updated', $request->user()->name.' updated the account for '.$user->name);

        return redirect()->route('users.show', $user)
            ->with('status', $user->name.'’s account has been updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Refused against yourself and against the owner account. See UserPolicy::delete().
        $this->authorize('delete', $user);

        $name = $user->name;

        DB::transaction(function () use ($user) {
            // Roles go first. A soft-deleted account that kept its grants would come back with
            // full reach the moment anyone restored it, without a second authorization.
            $user->roles()->detach();
            $user->is_active = false;
            $user->save();
            $user->delete();
            $user->organization?->bumpPermissionsVersion();
        });

        $this->activity->record($user, 'deleted', $request->user()->name.' disabled the account for '.$name);

        return redirect()->route('users.index')
            ->with('status', $name.'’s account has been disabled.');
    }

    /**
     * Replace this account's role assignments (§10).
     */
    public function updateRoles(UserRolesRequest $request, User $user, RolePolicy $roles): RedirectResponse
    {
        $this->authorize('assignRoles', $user);

        $actor = $request->user();
        $assignments = $request->assignments();
        $existing = $this->currentAssignments($user);

        // This form REPLACES every assignment, so three different things can happen to a role and
        // only one of them is "no change": added, re-pinned, removed. Every one of those that
        // differs from what the account already holds is authorized below; an assignment submitted
        // back exactly as it stands is not a decision and is left alone.
        //
        // Untouched assignments have to be exempt, or a bounded administrator could never edit
        // anyone holding a single role above their own reach — saving the form would have to strip
        // it. Removals must NOT be exempt: stripping a role you could not grant is how a
        // departmental administrator would disable an executive.
        $added = array_keys(array_filter(
            $assignments,
            fn (array $assignment, int $roleId) => ($existing[$roleId] ?? null) !== $assignment,
            ARRAY_FILTER_USE_BOTH,
        ));

        $removed = array_values(array_diff(array_keys($existing), array_keys($assignments)));
        $changed = array_values(array_unique([...$added, ...$removed]));

        $selected = Role::query()
            ->whereKey($changed)
            ->with('permissions:id,key')
            ->get();

        foreach ($selected as $role) {
            // Reach over the role itself...
            $this->authorize('assign', $role);

            // ...and no escalation through it. An administrator may only hand out — or take away —
            // permissions they already hold; otherwise every bounded administrator is bounded only
            // until they open this form.
            if (! $roles->mayGrantAll($actor, $role->permissions->pluck('key')->all())) {
                throw ValidationException::withMessages([
                    'roles' => '“'.$role->name.'” carries permissions you do not hold yourself, so you can neither grant nor remove it.',
                ]);
            }
        }

        // A pin must name a unit the actor can actually reach, or an administrator could bound
        // someone into a department they themselves cannot see — and then not be able to unwind it.
        foreach ($added as $roleId) {
            if ($assignments[$roleId]['scope_id'] !== null) {
                $this->authorize('view', OrgUnit::findOrFail($assignments[$roleId]['scope_id']));
            }
        }

        $before = $user->roles()->pluck('roles.name')->sort()->values()->all();

        DB::transaction(function () use ($user, $assignments, $actor) {
            $payload = [];

            foreach ($assignments as $roleId => $assignment) {
                $payload[$roleId] = [
                    'scope_type' => $assignment['scope_type'],
                    'scope_id' => $assignment['scope_id'],
                    'assigned_by' => $actor->id,
                ];
            }

            $user->roles()->sync($payload);
            $user->organization?->bumpPermissionsVersion();
        });

        $after = $user->roles()->pluck('roles.name')->sort()->values()->all();

        // Audited explicitly rather than through the model observer: nothing on the users row
        // changed, so a field-level diff would record this as no event at all — and a permission
        // change that leaves no trace is exactly the change worth tracing (§59).
        $this->audit->log(
            action: 'roles_assigned',
            subject: $user,
            old: ['roles' => $before],
            new: ['roles' => $after],
            description: $actor->name.' changed the roles held by '.$user->name,
            actor: $actor,
        );

        $this->activity->record($user, 'assigned', $actor->name.' updated the roles held by '.$user->name);

        return redirect()->route('users.show', $user)
            ->with('status', 'Roles for '.$user->name.' have been updated.');
    }

    /**
     * Send the account holder a link to set a new password.
     */
    public function resetPassword(Request $request, User $user, PasswordResetService $passwords): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $issued = $passwords->issueFor($user, $request->ip(), $request->user());

        if (! $issued) {
            return redirect()->route('users.show', $user)
                ->with('warning', 'A reset link was sent to this account very recently. Try again shortly.');
        }

        $this->activity->record($user, 'updated', $request->user()->name.' issued a password reset for '.$user->name);

        return redirect()->route('users.show', $user)
            ->with('status', 'A link to set a new password has been emailed to '.$user->email.'.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The roles offered on the edit form, each annotated with whether this actor may hand it out.
     *
     * Roles the actor cannot grant are still listed rather than hidden, because the form replaces
     * every assignment at once: a held role missing from the markup would be silently stripped on
     * save. They render locked, and updateRoles() re-checks the ones that actually changed.
     *
     * @return Collection<int,Role>
     */
    private function assignableRoles(User $actor, RolePolicy $policy): Collection
    {
        return $this->compiler
            ->apply(Role::query()->where('is_active', true), $actor, 'role.view')
            ->with('permissions:id,key')
            ->orderByDesc('level')->orderBy('name')
            ->get()
            ->each(function (Role $role) use ($actor, $policy) {
                $role->setAttribute(
                    'grantable',
                    $actor->can('assign', $role)
                        && $policy->mayGrantAll($actor, $role->permissions->pluck('key')->all()),
                );
            });
    }

    /**
     * This account's assignments in the same shape UserRolesRequest::assignments() produces, so the
     * form's current state and a submission can be compared field for field.
     *
     * @return array<int,array{scope_type:?string,scope_id:?int}>
     */
    private function currentAssignments(User $user): array
    {
        return $user->roles()->get()
            ->mapWithKeys(fn (Role $role) => [
                (int) $role->id => [
                    'scope_type' => $role->pivot->scope_type ?: null,
                    'scope_id' => $role->pivot->scope_id !== null ? (int) $role->pivot->scope_id : null,
                ],
            ])
            ->all();
    }

    /**
     * Units this actor may pin an assignment to — never the whole tree, only their own reach.
     *
     * @return array<int|string,string>
     */
    private function unitOptions(User $actor): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $actor, 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $unit) => [
                $unit->id => str_repeat('— ', max(0, (int) $unit->depth)).$unit->name,
            ])
            ->all();
    }

    /**
     * Human labels for each role assignment's pin, e.g. "limited to Lagos Yard".
     *
     * @return array<int,string>
     */
    private function scopeLabels(User $user): array
    {
        $unitIds = $user->roles
            ->filter(fn (Role $role) => $role->pivot->scope_type === 'org_unit' && $role->pivot->scope_id)
            ->pluck('pivot.scope_id')
            ->unique();

        if ($unitIds->isEmpty()) {
            return [];
        }

        $names = OrgUnit::whereKey($unitIds)->pluck('name', 'id');

        return $user->roles
            ->mapWithKeys(function (Role $role) use ($names) {
                $id = $role->pivot->scope_id;

                return [$role->id => $id && isset($names[$id]) ? $names[$id] : ''];
            })
            ->filter()
            ->all();
    }
}
