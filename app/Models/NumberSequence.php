<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The counter behind a numbering scheme (§64).
 *
 * Read and written by NumberGenerator through a locked transaction, never by a controller. It is
 * a model rather than a bare table only so the tenant scope covers it like everything else —
 * nothing in the application should ever list or edit these rows.
 *
 * Deliberately NOT Auditable: the audit trail records that PRJ-2026-0007 was issued to a project,
 * which is the fact anyone will ever need. An audit row per increment would double the size of
 * the busiest table in the system to record that a counter counted.
 */
class NumberSequence extends Model
{
    use BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(NumberingScheme::class, 'numbering_scheme_id');
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
}
