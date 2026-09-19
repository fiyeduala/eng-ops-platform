<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A heading on a form, and the questions under it.
 *
 * WHY SECTIONS EXIST AT ALL, given that a flat list of fields would render. A site inspection sheet
 * has forty questions on it; forty boxes in a column is a form people fill in wrongly, because
 * nothing tells them that questions eleven to nineteen are all about the same scaffold. Grouping is
 * not decoration on a long form — it is most of what makes a long form answerable.
 *
 * A section belongs to a VERSION, not to a form, so reorganising the page in a new edition does not
 * reach back and reorganise what people already answered.
 */
class FormSection extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public static function permissionModule(): string
    {
        return 'form';
    }

    // ------------------------------------------------------------------ relationships

    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** The questions on this section — top-level only; table columns hang off their parent field. */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)
            ->whereNull('parent_field_id')
            ->orderBy('sort')
            ->orderBy('id');
    }

    public function displayName(): string
    {
        return (string) $this->title;
    }

    // ------------------------------------------------------------------ access hooks

    public function accessParentRelation(): ?string
    {
        return 'version';
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessOrganizationWideAbilities(): array
    {
        return ['form.view'];
    }
}
