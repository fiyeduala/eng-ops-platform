<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody leaving: the date, the reason, what they still hold, and the account being switched off.
 *
 * CLEARANCE IS THE SYSTEM'S ANSWER, NOT THE LEAVER'S. Whether an exit can be completed is asked of
 * the registers that actually know — assets still issued to them, a petty cash float still in their
 * name — and never of a ticked box. A checklist records that somebody says a thing was done; a
 * blocker is a row that still exists, and only the second one survives the person having gone.
 *
 * COMPLETING AN EXIT CLOSES THE ACCOUNT. `exited_on` and `employment_status` on the personnel record
 * are facts about employment; the sign-in being switched off is the security consequence, and doing
 * one without the other is how a leaver keeps their access for three weeks. EmployeeExitService does
 * both in one transaction or neither.
 *
 * RESTRICTED BY DEFAULT (§68). A dismissal, a reason, and an exit interview in which somebody said
 * what they thought of their manager are not internal reading — least of all for the manager.
 */
class EmployeeExit extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notified_on' => 'date',
            'last_working_day' => 'date',
            'eligible_for_rehire' => 'boolean',
            'confidentiality' => Confidentiality::class,
            'cleared_at' => 'datetime',
            'access_revoked_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ access

    /**
     * An exit is read through the person it is about.
     *
     * The row has no unit of its own — an exit belongs to somebody, and where they sit is on their
     * personnel record — so reach is inherited: seeing that a colleague is leaving requires being
     * able to see the colleague, and a restricted exit is visible from inside their department.
     *
     * Without this pin nothing would satisfy the restricted branch of Gate 5 and `employee_exit.view`
     * would return an empty list to everybody, which would quietly make the split this class is
     * built around — the department knows somebody left, `view_confidential` reads what they said on
     * the way out — impossible to express (§68). Note the direction: this WIDENS who sees the fact of
     * the exit and not by one field who reads the interview, which stays behind EmployeeExitPolicy::viewInterview.
     */
    public function accessParentRelation(): ?string
    {
        return 'employee';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Restricted;
    }

    public function registerConditionFields(): array
    {
        return ['reason'];
    }

    /**
     * The reference only (§61).
     *
     * Not the leaver's name, and certainly not the notes: a search box that matches them tells
     * anyone who can list exits that a named colleague is on the way out, weeks before the company
     * has said so.
     *
     * @return array<int,string>
     */
    public function registerSearchColumns(): array
    {
        return ['number'];
    }

    public function registerTitleColumn(): string
    {
        return 'reason';
    }

    public function registerDueColumn(): ?string
    {
        return 'last_working_day';
    }

    // ------------------------------------------------------------------ relations

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EmployeeChecklist::class, 'employee_checklist_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    // ------------------------------------------------------------------ state

    public function isCompleted(): bool
    {
        return $this->statusKey() === 'completed';
    }

    public function isCleared(): bool
    {
        return $this->cleared_at !== null;
    }

    public function displayName(): string
    {
        return trim($this->number.' — exit');
    }

    /** @param  Builder<static>  $query */
    public function scopeForEmployee(Builder $query, int|string|null $employeeId): Builder
    {
        return blank($employeeId) ? $query : $query->where($this->qualifyColumn('employee_id'), $employeeId);
    }
}
