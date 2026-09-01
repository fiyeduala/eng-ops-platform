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
 * A named person at a vendor.
 *
 * Procurement corresponds with a person, not with a company, and that person leaves. Keeping
 * contacts as rows rather than as three columns on the vendor means an RFQ issued last year still
 * names whoever actually received it, while `is_primary` moves on to their replacement.
 *
 * DEACTIVATED, NOT DELETED. `is_active` is how somebody who has left is retired: the row stays so
 * historic correspondence keeps its addressee, and the contact stops appearing in the pickers. A
 * hard delete would leave an RFQ addressed to nobody.
 *
 * Reach is inherited from the vendor — see VendorContactPolicy. A contact must never be visible to
 * somebody the vendor is not.
 */
class VendorContact extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access

    /** A contact has no project and no unit of its own; the vendor answers both. */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ presentation

    public function displayName(): string
    {
        return trim((string) $this->name.($this->position ? ' ('.$this->position.')' : ''));
    }
}
