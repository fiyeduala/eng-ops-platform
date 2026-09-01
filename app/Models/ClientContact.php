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
 * A named person at a client.
 *
 * The same shape and the same reasoning as VendorContact — deactivated rather than deleted, so a
 * transmittal issued two years ago still names who received it. What it adds is the reason the
 * engineering registers wanted this table in the first place: an RFI is addressed TO a person at
 * the client, and until now that was a string somebody typed into `party_name`.
 *
 * Reach is inherited from the client. See ClientContactPolicy.
 */
class ClientContact extends Model
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access

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
