<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Report\ExecutiveBriefing;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's file, waiting to be collected.
 *
 * READ THIS BEFORE ADDING A SCOPE TO IT. An export is not a company record that happens to have an
 * owner — it is one person's visibility, frozen. The rows inside were selected by `visibleTo($that
 * person)` at the moment they clicked, and nothing about the file explains that to a second reader.
 * So there is no `visibleTo()` here and this model is deliberately not `Scopeable`: a permission
 * scope answers "which of these may this role see", and the correct answer for this table is "none
 * of anybody else's, whatever the role". `ownedBy()` is the only filter, ExportPolicy consults no
 * permission, and an administrator gets a 403 on a colleague's file (§61, §67).
 *
 * THE ROW OUTLIVES THE FILE, ON PURPOSE. `expires_at` passes, the sweep deletes the bytes, and the
 * row stays as `expired` carrying its row count, its truncation flag and both filter sets. "What
 * was in the export Adaeze took in March" is an audit question that arrives long after March, and
 * the file is the one thing that cannot answer it — it has gone missing, which is why the question
 * is being asked (§59).
 */
class Export extends Model
{
    use BelongsToOrganization;

    /**
     * Where a file can be in its life.
     *
     * `expired` is a real state rather than a derived one. A row whose `expires_at` has passed but
     * whose file is still on disk because the cron has not run is NOT expired — it is ready, and the
     * download route refuses it on the date rather than on the status. Deriving the status from the
     * clock would make those two disagree in the window between them, which is exactly the window
     * somebody is clicking a link in.
     *
     * @var array<string,string>
     */
    public const STATUSES = [
        'queued' => 'Waiting',
        'running' => 'Being prepared',
        'ready' => 'Ready',
        'failed' => 'Could not be produced',
        'expired' => 'No longer available',
    ];

    /**
     * The formats a stored export can be.
     *
     * CSV and PDF are here as well as in ReportDefinition::FORMATS because a large CSV is queued
     * too. The threshold is about the row count, not about the format — a hundred-thousand-row CSV
     * will outlive a cPanel timeout just as surely as a workbook will exhaust its memory, and it was
     * only ever safe before because ReportDefinition::MAX_ROWS refused it outright.
     *
     * @var array<string,string>
     */
    public const FORMATS = [
        'csv' => 'CSV (spreadsheet)',
        'xlsx' => 'Excel workbook',
        'docx' => 'Word document',
        'pdf' => 'PDF (print)',
    ];

    /** What each format is served as, and what it is called on disk. */
    public const MIME_TYPES = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf' => 'application/pdf',
    ];

    /** The private disk. Never `public`, and never configurable to it (§67). */
    public const DISK = 'local';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'ad_hoc_filters' => 'array',
            'truncated' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    // ================================================================== relations

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    // ================================================================== scopes

    /**
     * The only filter this model offers.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('requested_by', $user->getKey());
    }

    /**
     * Everything whose retention has run out and whose bytes are still on disk.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCollectable(Builder $query, CarbonInterface $asOf): Builder
    {
        return $query->whereNotNull('path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $asOf);
    }

    // ================================================================== state

    /**
     * Can this be handed over right now?
     *
     * The date is checked here rather than the status, for the reason STATUSES gives: between the
     * moment retention runs out and the moment the sweep runs, the row still says `ready` and the
     * file is still there — and that window must be a refusal, because retention is a promise about
     * time rather than a promise about cron.
     */
    public function isCollectable(): bool
    {
        return $this->status === 'ready'
            && $this->path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function statusLabel(): string
    {
        // Read through hasExpired() so a row the sweep has not reached yet reads honestly on screen
        // — the same disagreement isCollectable() exists to prevent, said in words.
        return $this->hasExpired() && $this->status === 'ready'
            ? self::STATUSES['expired']
            : (self::STATUSES[$this->status] ?? $this->status);
    }

    public function formatLabel(): string
    {
        return self::FORMATS[$this->format] ?? mb_strtoupper($this->format);
    }

    public function mimeType(): string
    {
        return self::MIME_TYPES[$this->format] ?? 'application/octet-stream';
    }

    /**
     * What a person calls this in a list.
     *
     * Named after the report rather than the file, because the filename carries a date stamp and a
     * list of four exports of one report on one afternoon should read as four attempts at the same
     * thing rather than four unrelated files.
     */
    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * Whether this file is the scheduled daily report rather than something somebody asked for.
     *
     * The distinction is not cosmetic. A briefing arrived unasked, counts SECTIONS rather than rows
     * in the `rows` column, and is kept for days rather than hours — so a page that describes every
     * row here in the vocabulary of an export tells a director they exported five rows of something
     * at seven this morning, which they did not.
     *
     * Read off `entity_type` rather than "has no report definition", because a report deleted after
     * its export was prepared also leaves that column null, and those two rows mean opposite things.
     */
    public function isBriefing(): bool
    {
        return $this->entity_type === ExecutiveBriefing::ENTITY_TYPE;
    }
}
