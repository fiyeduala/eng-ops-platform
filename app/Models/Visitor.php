<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who is in the building and does not work here.
 *
 * THE REGISTER IS A ROLL CALL FIRST AND A LOG SECOND. `onSite()` is the scope the whole thing exists
 * for, and it is deliberately written against the two timestamps rather than against the status: a
 * status is a label somebody sets and a timestamp is a thing that happened, and on the day it
 * actually matters the two must agree or the safer of them must win. They are kept in step by
 * VisitorService, which is the only thing allowed to set either.
 *
 * "MINE" MEANS I AM THE HOST. Like a training record, this is a register ABOUT somebody rather than
 * about work, and the person with the strongest claim to read it is the employee expecting the
 * visitor — not the receptionist who typed it in. Reception holds `visitor.view` at organization
 * scope and sees everything; a project engineer with `own` scope sees the people coming to see him.
 * Note that `own` never inherits through a parent relation, so this is the only route to it.
 *
 * NOT WORKFLOW-ROUTED, ON PURPOSE. `requiresWorkflow()` returns false — the single hard opt-out in
 * Phase 6. Every other register in this phase refuses to approve itself and waits for the engine;
 * this one refuses to route at all, because a receptionist with a person standing at the desk cannot
 * wait on an inbox, and a register that could be configured to make her wait would be a register
 * that quietly stops working the day somebody builds a route for it. The control here is a
 * permission at the desk (`visitor.check_in`), not a decision somewhere else.
 *
 * THE IDENTITY DOCUMENT IS ENCRYPTED AND HIDDEN. A passport number is the one field in this table
 * that would do real harm outside it, so it is encrypted at rest, excluded from array and JSON
 * output, and absent from `registerSearchColumns()` — a visitor book is not a place to look people
 * up by document number (§67).
 */
class Visitor extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    /** `visitors.purpose_types` — company vocabulary, not ours (§47). */
    public const DEFAULT_PURPOSE_TYPES = [
        'Meeting',
        'Delivery',
        'Contractor',
        'Interview',
        'Audit',
        'Inspection',
        'Other',
    ];

    /** Never in an array, never in JSON, never in a log line. */
    protected $hidden = ['id_document_number'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'id_document_number' => 'encrypted',
            'party_size' => 'integer',
            'expected_from' => 'datetime',
            'expected_to' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'auto_closed' => 'boolean',
            'requires_escort' => 'boolean',
            'induction_completed' => 'boolean',
            'induction_completed_on' => 'date',
            'induction_valid_until' => 'date',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function host(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function escort(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'escort_employee_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The host owns the visit.
     *
     * `host_user_id`, not `host_employee_id` — the engine compares owner columns against the acting
     * user's id, so the employee id would silently match the wrong people or nobody at all. The two
     * columns are written together by VisitorService; see the migration for why both exist.
     *
     * `created_by` is kept as a fallback because a walk-in with no host named still has to belong to
     * somebody, and that is the person at the desk who wrote it down.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['host_user_id', 'created_by'];
    }

    /*
     * No accessAssigneeColumns() override.
     *
     * An escort is an employee, and there is no user column for one — declaring `escort_employee_id`
     * here would compare an employee id against a user id and quietly hand people other visitors'
     * records. Escorts reach the visits they are on through their org unit or project scope, which
     * is the same way they reach everything else about the site they are standing on.
     */

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['purpose_type', 'location_id', 'project_id', 'requires_escort'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Visitors';
    }

    /** See the class docblock — the one hard opt-out in Phase 6. */
    public function requiresWorkflow(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------ state

    public function isOnSite(): bool
    {
        return $this->checked_in_at !== null && $this->checked_out_at === null;
    }

    public function hasDeparted(): bool
    {
        return $this->checked_out_at !== null;
    }

    /**
     * Everybody who came through the gate and has not gone back out.
     *
     * Written against the timestamps rather than the status on purpose: see the class docblock.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnSite(Builder $query): Builder
    {
        return $query->whereNotNull('checked_in_at')->whereNull('checked_out_at');
    }

    /**
     * Booked for a given day and not yet arrived.
     *
     * @param  Builder<static>  $query
     */
    public function scopeExpectedOn(Builder $query, \DateTimeInterface $day): Builder
    {
        return $query->whereNull('checked_in_at')
            ->whereNotNull('expected_from')
            ->whereDate('expected_from', $day);
    }

    /**
     * How many heads the roll call should be counting.
     *
     * A delegation arrives as one row. Anywhere the number of people on site is reported, it is this
     * that is summed — never the row count.
     */
    public function headcount(): int
    {
        return max(1, (int) $this->party_size);
    }

    public function inductionIsValid(): bool
    {
        if (! $this->induction_completed) {
            return false;
        }

        // A completed induction with no expiry does not lapse. Whether one ever expires, and after
        // how long, is an organization setting — this only reads what was written at the time.
        return $this->induction_valid_until === null
            || ! $this->induction_valid_until->isBefore(now()->startOfDay());
    }

    // ------------------------------------------------------------------ presentation

    /** The visitor's name is the title; there is no title column. */
    public function registerTitleColumn(): string
    {
        return 'full_name';
    }

    public function displayName(): string
    {
        $company = $this->company_name;

        return trim(($this->number ? $this->number.' — ' : '')
            .$this->full_name
            .($company !== null && $company !== '' ? ' · '.$company : ''));
    }

    /** No document number here, and no phone — see the class docblock. */
    public function registerSearchColumns(): array
    {
        return ['number', 'full_name', 'company_name', 'badge_number', 'vehicle_registration'];
    }

    public function registerDueColumn(): ?string
    {
        return 'expected_from';
    }
}
