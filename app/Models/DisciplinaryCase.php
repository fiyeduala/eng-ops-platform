<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An allegation against a named person, and what the company did about it.
 *
 * THIS IS THE MOST SENSITIVE RECORD IN THE PLATFORM, and every decision below follows from that one
 * sentence. It holds an accusation that may be untrue and that the company may itself conclude is
 * untrue; a leak does damage that a finding of "no case to answer" does not undo.
 *
 * SO IT DOES NOT INHERIT REACH FROM THE PERSON. Unlike an exit or a movement, there is deliberately
 * no `accessParentRelation` here. A record with no unit column and no parent satisfies the
 * restricted branch of Gate 5 in exactly two ways: the reader holds `disciplinary.view_confidential`,
 * or there is an explicit ACL row on this one case. Everybody else — including the subject's own
 * department head, including the director above them — gets an empty list, which is the correct
 * answer. Somebody's manager finding out through a register that an allegation has been made is the
 * failure this prevents.
 *
 * AN INVESTIGATOR IS GIVEN THE CASE, NOT THE REGISTER. DisciplinaryService writes a record-level
 * allow row when an investigator is assigned, so they can open the case they are investigating and
 * no other. It is the first real use of Gate 4 in the platform and it is exactly what Gate 4 is for.
 *
 * `summary` IS NOT SEARCHABLE, and nor is any other free-text column here (§61). A search box that
 * matched them would let anybody who can list cases discover what an allegation says, one guess at a
 * time, without ever opening the record.
 *
 * NOTHING ABOUT A CASE GOES IN A NOTIFICATION (§68). Not the category, not the outcome, not the
 * subject's name. The service records activity against the case, where the same authorization
 * applies to reading it; it never sends anybody a message whose subject line is the story.
 *
 * A SANCTION LAPSES. `sanction_expires_on` exists because most disciplinary codes say a warning
 * stays live for six or twelve months and then stops counting, and a system that cannot express that
 * turns every first offence into a permanent mark.
 */
class DisciplinaryCase extends Model
{
    use RegisterRecord;

    /** Starting vocabularies. The company owns all three from the first edit (§47). */
    public const DEFAULT_CATEGORIES = ['conduct', 'attendance', 'performance', 'safety', 'property', 'other'];

    public const SEVERITIES = ['minor', 'serious', 'gross'];

    public const DEFAULT_OUTCOMES = [
        'no_case', 'informal_advice', 'verbal_warning', 'written_warning',
        'final_written_warning', 'dismissal', 'other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'incident_on' => 'date',
            'reported_on' => 'date',
            'hearing_on' => 'date',
            'decided_on' => 'date',
            'sanction_expires_on' => 'date',
            'appeal_received_on' => 'date',
            'confidentiality' => Confidentiality::class,
            'closed_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'disciplinary';
    }

    // ------------------------------------------------------------------ access

    /**
     * Deliberately NOT pinned to the employee. See the class docblock — this is the one register in
     * the platform where returning an empty list to a department head is the intended behaviour.
     */
    public function accessParentRelation(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** The person asked to look into it, and the person who reported it. */
    public function accessAssigneeColumns(): array
    {
        return ['investigator_id', 'reported_by'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Restricted;
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['category', 'severity', 'outcome'];
    }

    /** The reference and nothing else. See the class docblock. */
    public function registerSearchColumns(): array
    {
        return ['number'];
    }

    public function registerTitleColumn(): string
    {
        return 'category';
    }

    public function registerDueColumn(): ?string
    {
        return 'hearing_on';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Disciplinary cases';
    }

    // ------------------------------------------------------------------ relations

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    // ------------------------------------------------------------------ state

    public function isClosedCase(): bool
    {
        return $this->closed_at !== null || $this->statusKey() === 'closed';
    }

    public function isDecided(): bool
    {
        return $this->outcome !== null && $this->decided_on !== null;
    }

    /** Whether a sanction still counts today. A lapsed warning is history, not a live sanction. */
    public function sanctionIsLive(): bool
    {
        if ($this->outcome === null || in_array($this->outcome, ['no_case', 'informal_advice'], true)) {
            return false;
        }

        return $this->sanction_expires_on === null
            || ! $this->sanction_expires_on->isBefore(now()->startOfDay());
    }

    public function outcomeLabel(): string
    {
        return $this->outcome === null
            ? 'Not decided'
            : ucfirst(str_replace('_', ' ', $this->outcome));
    }

    public function displayName(): string
    {
        return trim($this->number.' — '.ucfirst((string) $this->category));
    }

    /** @param  Builder<static>  $query */
    public function scopeForEmployee(Builder $query, int|string|null $employeeId): Builder
    {
        return blank($employeeId) ? $query : $query->where($this->qualifyColumn('employee_id'), $employeeId);
    }

    /** @param  Builder<static>  $query */
    public function scopeLiveSanctions(Builder $query): Builder
    {
        return $query->whereNotNull('outcome')
            ->whereNotIn('outcome', ['no_case', 'informal_advice'])
            ->where(function (Builder $q) {
                $q->whereNull('sanction_expires_on')
                    ->orWhereDate('sanction_expires_on', '>=', now()->startOfDay());
            });
    }
}
