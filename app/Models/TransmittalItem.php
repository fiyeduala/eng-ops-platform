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
 * One line on a transmittal: this exact revision, this many copies, on this medium.
 *
 * Points at a document VERSION, and that single choice is what makes the module worth having.
 * Pinning the document would make the record say "we sent them ENG-2026-014" — which by now reads
 * Revision F and is not what was in the envelope. Pinning the version makes it say "we sent them
 * ENG-2026-014 Revision C, sha256 abc…", which was true then and stays true.
 *
 * `copies` and `media` exist because paper is still real in construction. "Two prints, A1, by
 * courier" is a line an engineer needs to be able to write, and a system that can only describe
 * electronic issue quietly stops being the record of what was sent.
 */
class TransmittalItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const MEDIA = [
        'electronic' => 'Electronic',
        'paper' => 'Paper',
        'both' => 'Electronic and paper',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'copies' => 'integer',
            'sort' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class);
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

    /** A line has no reach of its own; it is part of its transmittal. */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public static function permissionModule(): string
    {
        return 'transmittal';
    }

    // ------------------------------------------------------------------ helpers

    public function mediaLabel(): string
    {
        return self::MEDIA[$this->media] ?? (string) $this->media;
    }

    public function displayName(): string
    {
        return $this->documentVersion?->displayName() ?? 'Transmittal item';
    }
}
