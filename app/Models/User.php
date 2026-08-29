<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Access\AccessResolver;
use App\Engines\Access\ActorProfile;
use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\ScopeSet;
use App\Support\Enums\AccessScope;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements MustVerifyEmail
{
    use Auditable, BelongsToOrganization, HasFactory, Notifiable, Scopeable, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'username', 'email', 'phone', 'password',
        'avatar_path', 'locale', 'timezone', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['scope_type', 'scope_id', 'starts_at', 'ends_at', 'assigned_by'])
            ->withTimestamps();
    }

    /** Role assignments that are in force today. */
    public function activeRoles(): BelongsToMany
    {
        $today = now()->toDateString();

        return $this->roles()
            ->where('roles.is_active', true)
            ->where(fn ($q) => $q->whereNull('role_user.starts_at')->orWhere('role_user.starts_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('role_user.ends_at')->orWhere('role_user.ends_at', '>=', $today));
    }

    // ------------------------------------------------------------------ access engine

    /** @return array<int,int> */
    public function roleIds(): array
    {
        return $this->relationLoaded('roles')
            ? $this->roles->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Gate 2: does the user hold this permission at any scope?
     *
     * This answers "may this KIND of person do this?" and is the right check for navigation,
     * create buttons and index routes. It is NEVER sufficient for acting on a specific record —
     * that must go through a policy so Gates 3, 4 and 5 also run.
     */
    public function hasPermission(string $permission): bool
    {
        return app(AccessResolver::class)->has($this, $permission);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        return app(AccessResolver::class)->hasAny($this, ...$permissions);
    }

    public function permissionScopes(string $permission): ScopeSet
    {
        return app(AccessResolver::class)->scopesFor($this, $permission);
    }

    public function hasOrganizationWide(string $permission): bool
    {
        return app(AccessResolver::class)->hasOrganizationWide($this, $permission);
    }

    /** Broadest organizational reach held for a permission, for UI hints. */
    public function reachFor(string $permission): ?AccessScope
    {
        return $this->permissionScopes($permission)->broadestOrganizational();
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('roles.slug', $slug)->exists();
    }

    /** @return array<int,int> */
    public function projectIds(): array
    {
        return app(ActorProfile::class)->projectIds($this);
    }

    public function orgUnitId(): ?int
    {
        return app(ActorProfile::class)->orgUnitId($this);
    }

    public function orgUnit(): ?OrgUnit
    {
        $id = $this->orgUnitId();

        return $id ? OrgUnit::find($id) : null;
    }

    /**
     * Assign a role, optionally pinned to a single org unit or project (section 10).
     */
    public function assignRole(Role $role, ?string $scopeType = null, ?int $scopeId = null, ?self $by = null): void
    {
        $this->roles()->syncWithoutDetaching([
            $role->id => [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'assigned_by' => $by?->id,
            ],
        ]);

        $this->organization?->bumpPermissionsVersion();
    }

    public function removeRole(Role $role): void
    {
        $this->roles()->detach($role->id);
        $this->organization?->bumpPermissionsVersion();
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * A user account is "owned" by the person it belongs to, so `user.view` at `own` scope
     * resolves to exactly one row: your own profile.
     */
    public function accessOwnerColumns(): array
    {
        return ['id'];
    }

    /**
     * Users are not attached to a unit or a project directly — their EMPLOYEE record is. Unit
     * and project scopes therefore reach no users at all, which fails closed rather than
     * silently reaching every user. Personnel lists are built from Employee, which does carry
     * org_unit_id; see docs/04-access-model.md §11.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function canLogIn(): bool
    {
        return $this->is_active && ! $this->isLocked() && $this->deleted_at === null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: '?';
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function positionTitle(): ?string
    {
        return $this->employee?->position?->title;
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
