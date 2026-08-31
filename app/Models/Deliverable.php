<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line on the Master Deliverable Register: something this project has promised to produce.
 *
 * THE DISTINCTION FROM A DOCUMENT IS THE POINT OF THE TABLE. A document is a file that exists; a
 * deliverable is an obligation that exists from the day the schedule is signed, whether or not
 * anybody has started it. Progress against a contract is measured against obligations, so a
 * register that can only count files can never say that something is late — the row simply is not
 * there. `document_id` closes the loop when the artefact finally appears, and stays null for the
 * many deliverables satisfied by something outside document control.
 *
 * TWO ISSUE DATES, NOT ONE. `planned_issue_on` is what was promised and does not move;
 * `forecast_issue_on` is what we now expect. Keeping both is what makes a slippage report possible,
 * and overwriting the plan with the forecast is precisely how a project reports itself on schedule
 * until the week it visibly is not.
 *
 * Reach: raising a deliverable makes it yours; being the responsible engineer is `assigned`, one
 * rung wider. That ordering matters — a discipline lead with `assigned` scope should see the fifty
 * deliverables routed to them, not gain owner-level rights over each.
 */
class Deliverable extends Model
{
    use RegisterRecord;

    /**
     * The starting vocabulary, used until a company edits `engineering.deliverable_types`.
     *
     * A fallback, not a constraint: OptionList reads the setting first and only lands here when
     * the company has never set one, so nothing in this list is a rule anybody has to live with.
     *
     * @var array<int,string>
     */
    public const DEFAULT_TYPES = [
        'Drawing', 'Calculation', 'Specification', 'Report', 'Data Sheet',
        'Model', 'Procedure', 'Schedule', 'Study',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'planned_start_on' => 'date',
            'planned_issue_on' => 'date',
            'forecast_issue_on' => 'date',
            'actual_issue_on' => 'date',
            'weight' => 'decimal:2',
            'progress_percent' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** The artefact that satisfies the promise, once it exists. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    // ------------------------------------------------------------------ access + workflow

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['responsible_id'];
    }

    /**
     * "Route calculations through the checker; route drawings straight to the lead."
     *
     * @return array<int,string>
     */
    public function registerConditionFields(): array
    {
        return ['type', 'responsible_id'];
    }

    /** A deliverable is late against the date it was PROMISED, not the date we now hope for. */
    public function registerDueColumn(): ?string
    {
        return 'planned_issue_on';
    }

    // ------------------------------------------------------------------ helpers

    public function isIssued(): bool
    {
        return $this->actual_issue_on !== null;
    }

    /**
     * The date this will actually land: what happened, else what we expect, else what we promised.
     *
     * Ordered that way on purpose — a forecast is only interesting until it is overtaken by fact.
     */
    public function effectiveIssueDate(): ?Carbon
    {
        return $this->actual_issue_on ?? $this->forecast_issue_on ?? $this->planned_issue_on;
    }

    /**
     * Days late against the promise. Negative means early; null when there is nothing to compare.
     *
     * Computed rather than stored, because a stored slippage is a number that silently stops being
     * true the moment either date is edited.
     */
    public function slippageDays(): ?int
    {
        $planned = $this->planned_issue_on;
        $actual = $this->effectiveIssueDate();

        if ($planned === null || $actual === null || $actual->equalTo($planned)) {
            return $planned === null || $actual === null ? null : 0;
        }

        return (int) $planned->diffInDays($actual, false);
    }

    /**
     * Progress weighted by this deliverable's share of scope.
     *
     * The register sums these to report a discipline's percentage complete. Weight defaults to 1,
     * so a company that never sets weights gets a straight average — which is wrong in theory and
     * far better than a blank dashboard.
     */
    public function weightedProgress(): float
    {
        return (float) $this->weight * ((int) $this->progress_percent / 100);
    }

    /** @param  Builder<static>  $query */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotNull('actual_issue_on');
    }

    /** @param  Builder<static>  $query */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('actual_issue_on');
    }
}
