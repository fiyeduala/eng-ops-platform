<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody who applied for a vacancy — an outside person, kept outside the personnel register.
 *
 * AN APPLICANT IS NOT AN EMPLOYEE. They have no user account, no org unit and no place in the
 * hierarchy, so none of the reach that personnel records get applies to them: an applicant is
 * reachable through the vacancy they applied to and through nothing else. Hiring them CREATES an
 * employee and links it back here; until that moment there is no personnel record to find.
 *
 * RESTRICTED BY DEFAULT (§68). A CV is a home address, a date of birth, an employment history and
 * frequently a photograph, handed to a company by somebody who does not work there and cannot ask
 * what became of it. `internal` would make every application readable by everybody with a login,
 * which is not what "we are hiring" means.
 *
 * THE CV IS A PRIVATE FILE (§67). `cv_path` is a key on the local disk under a generated name; the
 * uploaded name is data in `cv_name` and is never part of a path or echoed into a response header.
 */
class Applicant extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'cv_size' => 'integer',
            'offered_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** Vacancies and the people who applied to them are one module; one permission governs both. */
    public static function permissionModule(): string
    {
        return 'recruitment';
    }

    // ------------------------------------------------------------------ access

    /** Reach follows the vacancy — its hiring manager, its department, its project. */
    public function accessParentRelation(): ?string
    {
        return 'vacancy';
    }

    /** @return array<int,string> */
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

    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Restricted;
    }

    /**
     * Name and reference only (§61).
     *
     * Deliberately NOT the email address, the phone number or the notes. A search box that matches
     * them reports, through the result count alone, whether a given person has applied — which is
     * exactly the fact an applicant's current employer must not be able to confirm.
     *
     * @return array<int,string>
     */
    public function registerSearchColumns(): array
    {
        return ['number', 'first_name', 'last_name'];
    }

    public function registerConditionFields(): array
    {
        return ['vacancy_id', 'source'];
    }

    public function registerTitleColumn(): string
    {
        return 'last_name';
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ relations

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ApplicantStage::class)->orderBy('sequence');
    }

    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }

    // ------------------------------------------------------------------ presentation

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function displayName(): string
    {
        return trim($this->number.' — '.$this->fullName());
    }

    public function hasCv(): bool
    {
        return filled($this->cv_path);
    }

    /** Whether they have already been taken on, which is what stops a second hire. */
    public function isHired(): bool
    {
        return $this->hired_employee_id !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeForVacancy(Builder $query, int|string|null $vacancyId): Builder
    {
        return blank($vacancyId) ? $query : $query->where($this->qualifyColumn('vacancy_id'), $vacancyId);
    }
}
