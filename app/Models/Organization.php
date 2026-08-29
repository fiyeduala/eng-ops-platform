<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * The tenant root. Deliberately NOT tenant-scoped itself.
 */
class Organization extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'permissions_version' => 'integer',
        ];
    }

    public function orgUnits(): HasMany
    {
        return $this->hasMany(OrgUnit::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Invalidate every cached permission set in this organization at once.
     *
     * The counter is part of the cache key, so bumping it makes every existing entry
     * unreachable without scanning or deleting anything. Called whenever roles, grants,
     * assignments or org structure change.
     */
    public function bumpPermissionsVersion(): void
    {
        DB::table('organizations')->where('id', $this->id)->increment('permissions_version');
        $this->permissions_version = ($this->permissions_version ?? 0) + 1;
    }

    public function rootUnits(): HasMany
    {
        return $this->orgUnits()->whereNull('parent_id');
    }
}
