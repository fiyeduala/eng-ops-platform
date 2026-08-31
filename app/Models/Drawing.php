<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in the drawing register: the title block, as data.
 *
 * THIS TABLE HOLDS NO FILES, and that boundary is the whole design. Everything a controlled file
 * needs — revisions, a hash, signatures, private storage, download authorization (§67), transmittals
 * — lives in the document module, so there is exactly one place a file can be and exactly one set of
 * rules deciding who may open it. `document()` is the pointer; a drawing with no document is a
 * register line for something not yet drawn, which is the normal state of most of a project's
 * drawing schedule for most of its life.
 *
 * WHAT JUSTIFIES A SEPARATE TABLE, given that. Two things, and neither is negotiable in practice:
 *
 *   - The columns are drawing columns. Sheet 3 of 12, A1, 1:50, Rev C, Issued For Construction. A
 *     memo has no sheet size, and putting these on `documents` would give ninety per cent of the
 *     rows ninety per cent nulls.
 *   - The register predates the files, exactly as the deliverable register does. A drawing schedule
 *     is drawn up at kick-off; a system that can only list drawings that exist can never report on
 *     the ones that do not.
 *
 * `purpose` — IFR, IFA, IFC, AB — is a string against a configurable list, not an enum. The
 * abbreviations belong to the contract, projects differ, and nothing in the application branches on
 * the value.
 */
class Drawing extends Model
{
    use RegisterRecord;

    /**
     * Starting issue purposes, used until a company edits `engineering.drawing_purposes`.
     *
     * @var array<int,string>
     */
    public const DEFAULT_PURPOSES = [
        'For Review', 'For Approval', 'For Construction', 'For Information',
        'For Tender', 'As Built', 'Superseded',
    ];

    /**
     * ISO sheet sizes, which are not configurable and should not be.
     *
     * Unlike a purpose or a deliverable type, these are an international standard with fixed
     * dimensions that a plotter and a title block both depend on. A company that let somebody type
     * "A2.5" into this list would produce a register whose sheet sizes cannot be printed.
     *
     * @var array<int,string>
     */
    public const SHEET_SIZES = ['A0', 'A1', 'A2', 'A3', 'A4', 'ANSI D', 'ANSI E', 'ARCH D', 'ARCH E'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'sheet_number' => 'integer',
            'sheet_count' => 'integer',
            'revision_date' => 'date',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** The controlled file, once it exists. Everything about the file itself lives over there. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The promise on the MDR this drawing satisfies, when the register is being run that way. */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    public function drawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drawn_by');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['drawn_by', 'checked_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['purpose', 'sheet_size'];
    }

    /** A register line has no deadline of its own; the deliverable it belongs to has one. */
    public function registerDueColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    /** "Sheet 3 of 12", or just "Sheet 3", or nothing. */
    public function sheetLabel(): ?string
    {
        if ($this->sheet_number === null) {
            return null;
        }

        return 'Sheet '.$this->sheet_number
            .($this->sheet_count !== null ? ' of '.$this->sheet_count : '');
    }

    public function displayName(): string
    {
        $revision = $this->revision ? ' Rev '.$this->revision : '';

        return trim(($this->number ? $this->number.$revision.' — ' : '').(string) $this->title);
    }

    /** Whether a controlled file has been attached yet. */
    public function hasFile(): bool
    {
        return $this->document_id !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeWithoutFile(Builder $query): Builder
    {
        return $query->whereNull('document_id');
    }
}
