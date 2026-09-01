<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * It does not meet the specification.
 *
 * THE DISPOSITION IS NOT THE STATUS, exactly as on change requests, and keeping them apart is the
 * point of the record. Status is where the NCR has got to in this company's process; disposition is
 * the engineering answer — rework, repair, use as is, reject, regrade. An NCR can be closed (status)
 * as use-as-is (disposition), and "how much did we accept as-is this quarter" is a question no
 * status column can answer.
 *
 * USE-AS-IS IS THE REASON DISPOSITIONS NEED JUSTIFICATION. Accepting non-conforming work is a
 * legitimate and common engineering decision, and it is also the one most likely to be questioned
 * years later by somebody holding a failed component. The service refuses a use-as-is or repair
 * disposition with no notes, for the same reason a change request refuses an unexplained rejection.
 *
 * NOBODY DISPOSITIONS THEIR OWN. NcrPolicy refuses the author at any scope — the same rule change
 * requests use, and here because the person who raised the non-conformance deciding that it can be
 * left as it is would make the register self-cancelling.
 *
 * `requirement` MATTERS MORE THAN `description`. "Welds are bad" is an opinion; "AWS D1.1 Table 6.1
 * requires undercut ≤ 1mm, measured 2.5mm" is a finding. Giving the clause a column of its own is
 * the cheapest quality intervention this schema can make.
 *
 * CORRECTIVE ACTIONS LIVE IN THE CORRECTIVE ACTION REGISTER, opened from here by the same
 * `create_record` workflow verb an incident uses. One register, one "what is outstanding against
 * me" query, one place to look (§90).
 */
class Ncr extends Model
{
    use RegisterRecord;

    protected $table = 'ncrs';

    protected $guarded = ['id'];

    /** `ncr.severities`. */
    public const DEFAULT_SEVERITIES = ['Minor', 'Major', 'Critical'];

    /** `ncr.categories`. */
    public const DEFAULT_CATEGORIES = [
        'Workmanship', 'Material', 'Documentation', 'Dimensional', 'Welding',
        'Coating', 'Testing', 'Process',
    ];

    /** `ncr.sources` — who found it, which is what a supplier scorecard is built from. */
    public const DEFAULT_SOURCES = [
        'Inspection', 'Audit', 'Client', 'Supplier', 'Self identified', 'Test failure',
    ];

    /**
     * `ncr.dispositions` — the engineering answer.
     *
     * Slugged, these are rework, repair, use_as_is, reject and regrade, and three of them appear
     * in DISPOSITIONS_NEEDING_NOTES below. A company that renames them loses that pairing, which
     * is why the constant there lists slugs rather than reading this list: a justification
     * requirement must not be switchable by editing a label.
     */
    public const DEFAULT_DISPOSITIONS = ['Rework', 'Repair', 'Use as is', 'Reject', 'Regrade'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'detected_on' => 'date',
            'required_by' => 'date',
            'responded_at' => 'datetime',
            'dispositioned_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
            'cost_impact' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function qualityInspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class);
    }

    public function detector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detected_by');
    }

    public function dispositioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispositioned_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function correctiveActions(): MorphMany
    {
        return $this->morphMany(CorrectiveAction::class, 'source')->orderByDesc('id');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['detected_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        // Severity is what every routing rule branches on — critical to the quality manager, minor
        // to the site engineer — and disposition is what a closure route tests.
        return ['severity', 'category', 'source', 'disposition'];
    }

    // ------------------------------------------------------------------ state

    public function isDispositioned(): bool
    {
        return $this->dispositioned_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isClosedOut(): bool
    {
        return $this->closed_at !== null;
    }

    public function hasResponse(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * Dispositions that cannot be recorded without an explanation.
     *
     * Rework and reject speak for themselves — the work is being done again or thrown away. Repair
     * and use-as-is are decisions to keep non-conforming work in the permanent works, and both are
     * the ones somebody will be asked to justify. `regrade` is here too: reclassifying material to
     * a lower duty is only defensible if the reasoning is written down.
     */
    public const DISPOSITIONS_NEEDING_NOTES = ['repair', 'use_as_is', 'regrade'];

    public function dispositionNeedsNotes(?string $disposition = null): bool
    {
        return in_array((string) ($disposition ?? $this->disposition), self::DISPOSITIONS_NEEDING_NOTES, true);
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeUndispositioned(Builder $query): Builder
    {
        return $query->whereNull('dispositioned_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->whereNull('responded_at')->whereNull('closed_at');
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'location', 'party_name'];
    }

    public function registerDueColumn(): ?string
    {
        return 'required_by';
    }

    /** Derived from the class name this would read "Ncrs". */
    public static function workflowEntityLabel(): string
    {
        return 'Non-conformance reports';
    }
}
