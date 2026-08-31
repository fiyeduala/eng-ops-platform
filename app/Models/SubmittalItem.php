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
 * One line in a submittal package, and the verdict on that line.
 *
 * Points at a document VERSION rather than a document, for the same reason TransmittalItem does:
 * pinning the document would make the record say "they coded ENG-2026-014 as B", which by now reads
 * Revision F and is not what the reviewer looked at. Pinning the version keeps the sentence true.
 *
 * NULLABLE ON PURPOSE. Half the contents of a real submittal — a vendor's catalogue page, a
 * certificate scanned at site, a manufacturer's datasheet — were never brought into document
 * control and never will be. A register that refuses to record them is a register people keep in a
 * spreadsheet instead, which is how the module fails (§90). `description` and `reference` carry the
 * line when there is no controlled file behind it.
 *
 * Reach is inherited, not computed: the policy defers to the parent submittal, so an item can never
 * be visible to somebody the package is not.
 */
class SubmittalItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function submittal(): BelongsTo
    {
        return $this->belongsTo(Submittal::class);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * An item has no unit, project or assignee of its own — it has a parent.
     *
     * Returning null for the org unit and project columns keeps the compiler from emitting SQL for
     * columns this table does not have; the policy is what actually decides reach, by asking the
     * submittal.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** Governed by its parent's permissions; it is not a module of its own. */
    public static function permissionModule(): string
    {
        return 'submittal';
    }

    // ------------------------------------------------------------------ helpers

    public function displayName(): string
    {
        return trim(($this->reference ? $this->reference.' — ' : '').(string) $this->description);
    }
}
