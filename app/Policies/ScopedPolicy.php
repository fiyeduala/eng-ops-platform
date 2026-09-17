<?php

declare(strict_types=1);

namespace App\Policies;

use App\Engines\Access\AccessResolver;
use App\Engines\Access\ActorProfile;
use App\Engines\Access\ScopeCompiler;
use App\Engines\Access\Scopes\OrganizationScope;
use App\Models\Organization;
use App\Models\User;
use App\Support\Enums\AclEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single implementation of the five-gate access model (docs/04-access-model.md).
 *
 * Every module policy extends this and overrides only genuinely module-specific rules, calling
 * parent:: for the rest. No module can accidentally skip a gate, because no module implements
 * the gates.
 *
 *   Gate 0  Account        — the account is usable and its tenant is not suspended
 *   Gate 1  Organization   — the record belongs to the actor's tenant
 *   Gate 2  Permission     — the actor holds module.ability at some scope
 *   Gate 3  Scope          — that reach actually contains THIS record
 *   Gate 4  Record ACL     — explicit deny always wins; explicit allow lifts gates 3 and 5
 *   Gate 5  Confidentiality— restricted/confidential records need unit reach or an ACL row
 *
 * Gate 3 and Gate 5 are answered by re-running ScopeCompiler against a single-row query. That is
 * deliberate: the policy and the index page then share ONE definition of reach, so it is
 * structurally impossible for a record to appear in a list the user cannot open, or — far worse —
 * to be openable while absent from every list that would have revealed the mistake.
 */
abstract class ScopedPolicy
{
    /**
     * Permission namespace for this module, e.g. 'document' -> 'document.view'.
     * Falls back to the snake-cased model basename.
     */
    protected string $module = '';

    /**
     * Laravel ability name => permission suffix. Anything not listed is snake_cased as-is,
     * so a custom ability `requestRevision` becomes `<module>.request_revision`.
     *
     * The names on the two sides differ on purpose. Laravel's convention is `update`; the
     * permission catalogue says `edit`, because that is the word on the button and in the role
     * editor an administrator actually reads. Mapping them here keeps both audiences honest —
     * and mapping `update` to a non-existent `<module>.update` would silently deny every edit in
     * the system, since the resolver fails closed.
     *
     * `forceDelete` maps to `<module>.delete_permanently`, which NO module declares. Permanent
     * destruction of a record is therefore denied to everyone, including the organization owner,
     * until someone deliberately adds that permission to the catalogue. For a system whose audit
     * trail is a compliance artefact (§59), that is the right default.
     *
     * @var array<string,string>
     */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        // Restoring is the same custodial authority as deleting: whoever may remove a record may
        // put it back. Modules with a distinct `restore` permission (documents) override this.
        'restore' => 'delete',
        'forceDelete' => 'delete_permanently',
    ];

    public function __construct(
        protected readonly AccessResolver $resolver,
        protected readonly ScopeCompiler $compiler,
    ) {}

    // ------------------------------------------------------------------ standard abilities

    public function viewAny(User $user): bool
    {
        return $this->check($user, 'viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->check($user, 'view', $record);
    }

    public function create(User $user): bool
    {
        return $this->check($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->check($user, 'update', $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->check($user, 'delete', $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->check($user, 'restore', $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->check($user, 'forceDelete', $record);
    }

    // ------------------------------------------------------------------ the five gates

    /**
     * The whole access model, in order. Every ability in the system resolves through here.
     */
    public function check(User $user, string $ability, ?Model $record = null): bool
    {
        // --- Gate 0: the account and the tenant ----------------------------------------
        // A disabled or locked account authorizes nothing, and neither does any account inside a
        // suspended organization — regardless of roles, and including the organization owner.
        //
        // The tenant half is checked here as well as in EnsureOrganizationContext because the
        // middleware only stands in front of HTTP. Queued jobs, scheduled reports and console
        // commands reach these policies directly, and suspension has to mean the same thing on
        // every path or it means nothing on the ones nobody was looking at.
        if (! $user->canLogIn() || ! $this->resolver->tenantIsActive($user)) {
            return false;
        }

        $permission = $this->permissionFor($ability);

        // --- Gate 1: organization ------------------------------------------------------
        // Checked first and unconditionally. Nothing below can override it — not an ACL grant,
        // not super-admin, not a pinned role. Cross-tenant access simply does not exist.
        if ($record !== null && ! $this->sameOrganization($user, $record)) {
            return false;
        }

        // --- Gate 4a: explicit deny ----------------------------------------------------
        // Evaluated before any allow path, because a deny that could be out-voted by a broad
        // role grant would not be a deny. "Everyone in Engineering except Sam" must hold.
        if ($record !== null && $this->hasAclRow($user, $record, $permission, AclEffect::Deny)) {
            return false;
        }

        // --- Gate 2: permission --------------------------------------------------------
        // Held at SOME scope. This asks "may this kind of person do this at all?" and is
        // mandatory even for explicitly shared records: sharing a document does not confer the
        // ability to approve documents on someone who has never held it.
        if (! $this->resolver->has($user, $permission)) {
            return false;
        }

        // Class-level abilities (viewAny, create) stop here — there is no record to reach.
        if ($record === null) {
            return true;
        }

        // --- Gate 4b: explicit allow ---------------------------------------------------
        // A direct grant on this record lifts reach and confidentiality, which is exactly what
        // "share this drawing with the client's reviewer" has to mean.
        if ($this->hasAclRow($user, $record, $permission, AclEffect::Allow)
            && (! method_exists($record, 'accessParentRelation') || $record->accessParentRelation() === null)) {
            return true;
        }

        // --- Gates 3 and 5: scope and confidentiality ----------------------------------
        return $this->withinReach($user, $record, $permission);
    }

    /**
     * Gate 1.
     *
     * Three cases, and the middle one is the easy thing to get wrong:
     *
     *   1. The record carries organization_id — compare it to the actor's. The normal path.
     *   2. The record IS an Organization — it has no organization_id, its own key plays that
     *      role. Without this branch an Organization would fall through to case 3 and every
     *      tenant's profile would be reachable from every other tenant.
     *   3. Neither — genuinely platform-level reference data (the permission catalogue). Falls
     *      through to the remaining gates, which still require the permission.
     */
    protected function sameOrganization(User $user, Model $record): bool
    {
        if ($record instanceof Organization) {
            return (int) $record->getKey() === (int) $user->organization_id;
        }

        $recordOrganization = $record->getAttribute('organization_id');

        if ($recordOrganization === null) {
            return true;
        }

        return (int) $recordOrganization === (int) $user->organization_id;
    }

    /**
     * Gates 3 and 5, expressed as the same SQL the index page uses.
     */
    protected function withinReach(User $user, Model $record, string $permission): bool
    {
        $query = $record->newQuery()
            ->withoutGlobalScopes([OrganizationScope::class])
            ->whereKey($record->getKey());

        // A soft-deleted record must still be authorizable, or `restore` could never pass.
        if (in_array(SoftDeletes::class, class_uses_recursive($record), true)) {
            $query->withTrashed();
        }

        // Gate 1 already passed; re-assert it in SQL so the removed global scope cannot widen
        // the query if a caller reaches this method by another path.
        if ($record->getAttribute('organization_id') !== null) {
            $query->where($record->qualifyColumn('organization_id'), $user->organization_id);
        }

        return $this->compiler->apply($query, $user, $permission)->exists();
    }

    /**
     * Gate 4. Matches an unexpired ACL row addressed to the user directly, to any role they
     * hold, or to their org unit — for this exact ability or the '*' wildcard.
     */
    protected function hasAclRow(User $user, Model $record, string $permission, AclEffect $effect): bool
    {
        $roleIds = $user->roleIds();
        $orgUnitId = app(ActorProfile::class)->orgUnitId($user);

        return DB::table('record_permissions')
            ->where('record_type', $record->getMorphClass())
            ->where('record_id', $record->getKey())
            ->where('effect', $effect->value)
            ->whereIn('ability', [$permission, '*'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($q) use ($user, $roleIds, $orgUnitId) {
                $q->where(fn ($w) => $w->where('grantee_type', 'user')->where('grantee_id', $user->id));

                if ($roleIds !== []) {
                    $q->orWhere(fn ($w) => $w->where('grantee_type', 'role')->whereIn('grantee_id', $roleIds));
                }

                if ($orgUnitId !== null) {
                    $q->orWhere(fn ($w) => $w->where('grantee_type', 'org_unit')->where('grantee_id', $orgUnitId));
                }
            })
            ->exists();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Map a Laravel ability to a permission key. Public so controllers, Blade and the navigation
     * builder can ask the same question without duplicating the convention.
     */
    public function permissionFor(string $ability): string
    {
        $suffix = $this->abilityMap[$ability] ?? Str::snake($ability);

        return $this->module().'.'.$suffix;
    }

    protected function module(): string
    {
        if ($this->module !== '') {
            return $this->module;
        }

        // App\Policies\PurchaseOrderPolicy -> purchase_order
        return Str::snake(Str::replaceLast('Policy', '', class_basename(static::class)));
    }

    /**
     * Escape hatch for module policies that need a state check on top of the gates, e.g.
     * "approved documents cannot be edited". Always compose with parent::check(), never instead.
     */
    protected function deny(): bool
    {
        return false;
    }
}
