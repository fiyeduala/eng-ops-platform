<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named list of words a company chose for itself (§63).
 *
 * Disciplines, incident causes, document classes, concrete grades — the vocabulary that is not a
 * status because it does not decide what may happen next. A dropdown custom field names one of
 * these by key and offers whatever is in it.
 */
class OptionList extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OptionListItem::class)->orderBy('sort')->orderBy('label');
    }

    /** The items a form should offer — retired ones are readable but never selectable again. */
    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true);
    }

    /**
     * A list the platform provisioned and whose key code may name as a literal.
     *
     * Same contract as Status::isSystem(): the label is the company's to change, the key is not,
     * and it cannot be deleted. See OptionListService for what that buys.
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * Reading the vocabulary is company-wide.
     *
     * A list of disciplines is not confidential and appears on every form that uses it; scoping it
     * to a department would mean a dropdown that renders differently depending on who is looking,
     * which is a data-integrity problem dressed as a permission.
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['custom_field.view'];
    }
}
