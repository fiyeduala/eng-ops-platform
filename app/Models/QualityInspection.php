<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "The rebar is ready — come and look before we pour."
 *
 * THE REQUEST AND THE RESULT ARE ONE RECORD. In construction the same document is raised as a
 * request for inspection, scheduled, attended, and then carries the verdict. Splitting request from
 * result would double the register and lose the number everybody actually wants from it: how long
 * between asking and being inspected, which is the number that explains the programme.
 *
 * A REJECTED INSPECTION IS NOT AN NCR, and this register does not try to be one. The result records
 * what the inspector found; if it needs a formal non-conformance with a disposition, a cost and a
 * closure, that is an NCR raised from here — a different record with a different life, frequently
 * outliving this one by months.
 *
 * `witnessed_by` IS FREE TEXT because the witness is usually the client's or the certifying
 * authority's representative, and neither has a login until Phase 5 gives them records. Same
 * reasoning as `party_name` on the engineering registers: model the outside world as text now,
 * point at a record later, migrate nothing.
 */
class QualityInspection extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    /**
     * `quality_inspection.types` — the ITP vocabulary.
     *
     * Hold point and witness point are not synonyms, and the difference costs money: work may
     * continue past a witness point if the inspector does not attend, and may not past a hold
     * point. A register that collapsed them would be unusable against an inspection and test plan.
     */
    public const DEFAULT_TYPES = [
        'Inspection point', 'Witness point', 'Hold point', 'Surveillance', 'Final inspection',
    ];

    /**
     * `quality_inspection.results`.
     *
     * These slugs are the ones QualityInspectionService::RESULT_STATUS knows how to map onto a
     * status. A company is free to rename them — the map also accepts the common alternatives —
     * and a result it cannot map simply leaves the status where the workflow put it, which is the
     * correct failure: a vocabulary change must never move a record somewhere nobody asked for.
     */
    public const DEFAULT_RESULTS = ['Passed', 'Passed with comment', 'Rejected'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'scheduled_for' => 'datetime',
            'required_by' => 'date',
            'inspected_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function items(): HasMany
    {
        return $this->hasMany(QualityInspectionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /** Non-conformances raised out of this inspection. */
    public function ncrs(): HasMany
    {
        return $this->hasMany(Ncr::class)->orderByDesc('id');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['inspected_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        // Hold points route differently from surveillance, and a rejected result is what triggers
        // an NCR route — both are structured, neither is narrative (§61).
        return ['inspection_type', 'result'];
    }

    // ------------------------------------------------------------------ state

    public function wasInspected(): bool
    {
        return $this->inspected_at !== null;
    }

    /**
     * Days between the request and the visit, or null while it is still outstanding.
     *
     * The register's one metric. Null rather than zero while waiting, so an average is taken over
     * inspections that actually happened rather than being dragged towards nothing by every open
     * request.
     */
    public function responseDays(): ?int
    {
        if ($this->inspected_at === null || $this->scheduled_for === null) {
            return null;
        }

        return (int) $this->scheduled_for->startOfDay()->diffInDays($this->inspected_at->startOfDay(), false);
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaiting(Builder $query): Builder
    {
        return $query->whereNull('inspected_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('result', 'rejected');
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title', 'itp_reference', 'activity', 'location'];
    }

    public function registerDueColumn(): ?string
    {
        return 'required_by';
    }
}
