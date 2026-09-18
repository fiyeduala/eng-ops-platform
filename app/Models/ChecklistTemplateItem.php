<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a template: the thing to be done, and who is expected to do it.
 *
 * RESPONSIBILITY IS A ROLE, NOT A PERSON. "IT issues the laptop" survives the IT manager leaving;
 * "Musa issues the laptop" does not, and a template naming people is a template that quietly stops
 * being true. The person who ticks the item is recorded on the copy, where naming a person is a fact
 * rather than an expectation.
 */
class ChecklistTemplateItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'requires_note' => 'boolean',
        ];
    }

    public static function permissionModule(): string
    {
        return 'checklist';
    }

    public function accessParentRelation(): ?string
    {
        return 'template';
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

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function responsibleRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'responsible_role_id');
    }

    public function displayName(): string
    {
        return (string) $this->label;
    }
}
