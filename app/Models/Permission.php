<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The global permission catalogue — the vocabulary of things that can be done.
 *
 * Deliberately NOT tenant-scoped: "document.approve" means the same thing in every organization.
 * What differs per tenant is which roles hold it and at what scope.
 */
class Permission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('scope')->withTimestamps();
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public static function key(string $module, string $action): string
    {
        return $module.'.'.$action;
    }
}
