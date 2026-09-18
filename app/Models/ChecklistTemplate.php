<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a company does when somebody joins, or leaves — as rows an administrator edits (§47, §73).
 *
 * The steps are not in the code. A consultancy issues a laptop and a mailbox; a construction company
 * issues boots, a helmet and a site induction, and neither list survives contact with the other. So
 * this is configuration, per organization, editable in Settings, and the platform knows only that a
 * checklist has items and that items get ticked.
 *
 * A TEMPLATE IS A STARTING POINT, NOT A LIVE LINK. Starting somebody's checklist COPIES these rows
 * onto them (see EmployeeChecklist). Editing the template in June must not rewrite what a person was
 * asked to do in March — the copy is what was actually required of them, and an audit a year later
 * is asking about the copy.
 */
class ChecklistTemplate extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable, SoftDeletes;

    public const KIND_ONBOARDING = 'onboarding';

    public const KIND_EXIT = 'exit';

    public const KINDS = [self::KIND_ONBOARDING, self::KIND_EXIT];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Templates and the checklists made from them share one permission module. */
    public static function permissionModule(): string
    {
        return 'checklist';
    }

    // ------------------------------------------------------------------ access

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ relations

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class)->orderBy('sequence');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    // ------------------------------------------------------------------ queries

    /** @param  Builder<static>  $query */
    public function scopeOfKind(Builder $query, ?string $kind): Builder
    {
        return blank($kind) ? $query : $query->where($this->qualifyColumn('kind'), $kind);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    public function displayName(): string
    {
        return (string) $this->name;
    }
}
