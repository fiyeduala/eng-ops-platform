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
 * One step of an interview process, and what came of it.
 *
 * ROWS, NOT COLUMNS ON THE APPLICANT. How many steps there are is the company's business: a
 * telephone screen and a panel today, a technical test and a site visit in June. Columns would make
 * that a migration, and would make a three-stage process impossible to record without inventing a
 * fourth column nobody uses.
 *
 * NOTHING HERE DECIDES ANYTHING. Passing the last stage does not hire anybody — a stage records what
 * an interviewer observed, and taking somebody on is a separate act with its own permission. The two
 * are conflated often enough in HR systems that a failed reference check quietly becomes an offer.
 */
class ApplicantStage extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    public const OUTCOMES = ['pending', 'passed', 'failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'score' => 'integer',
            'scheduled_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'recruitment';
    }

    // ------------------------------------------------------------------ access

    /** Reach follows the applicant, and through them the vacancy. */
    public function accessParentRelation(): ?string
    {
        return 'applicant';
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /**
     * The interviewer, who must be able to see the step they are asked to conduct.
     *
     * Stated rather than left to Scopeable's default of `assignee_id`, which this table has not got
     * — an unhandled default compiles SQL naming a missing column and 500s the page for exactly the
     * users whose scope is `assigned`.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['interviewer_id'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ relations

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // ------------------------------------------------------------------ state

    public function isRecorded(): bool
    {
        return $this->recorded_at !== null;
    }

    public function displayName(): string
    {
        return (string) $this->name;
    }
}
