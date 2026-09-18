<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicantStage;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Applications, the interview trail, and the one act that turns an applicant into a colleague.
 *
 * APPLICATIONS ONLY AGAINST AN OPEN VACANCY. Every write here asks VacancyService::requireOpen()
 * first, which is what makes "approved means open for applications" true rather than decorative: a
 * head that has not been through its route, or one that has been filled or withdrawn, cannot
 * accumulate a shortlist for somebody to point at later.
 *
 * THE CV IS A PRIVATE FILE (§67). Validated on MIME, extension and size before it is stored; written
 * under a generated name so nothing about the person is in the path; served only by a controller
 * that authorises the applicant record first (§84). The uploaded name is kept as DATA, and the
 * download builds its own filename rather than echoing it back.
 *
 * HIRING IS ONE TRANSACTION AND HAPPENS ONCE. It writes the personnel record, links it back to the
 * application and counts the head against the vacancy, with the vacancy row locked. Hiring the same
 * applicant twice — two tabs, two clicks, a double submit — would otherwise create two people.
 *
 * IT DOES NOT CREATE A SIGN-IN. A personnel record is who works here; an account is what they can
 * reach, and deciding the second is an administrator's job with its own permissions. Handing every
 * new starter a login because HR pressed Hire is how people arrive with more access than anyone
 * chose to give them.
 */
class ApplicantService extends RegisterService
{
    /** A CV is a document or a scan of one. Never an archive, and never anything executable. */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    public const MAX_FILE_BYTES = 8 * 1024 * 1024;

    /** Statuses from which somebody can still be taken forward. */
    public const LIVE = ['applied', 'screening', 'interview', 'offered'];

    /** Held only for the length of createFor(). See beforeCreate() below. */
    private ?Vacancy $filingAgainst = null;

    protected function entityType(): string
    {
        return 'applicant';
    }

    protected function modelClass(): string
    {
        return Applicant::class;
    }

    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'vacancy_id', 'confidentiality',
            'cv_path', 'cv_name', 'cv_mime', 'cv_size',
            'offered_at', 'decided_at', 'decision_reason', 'hired_employee_id',
        ];
    }

    /**
     * Record an application against a vacancy.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function createFor(Vacancy $vacancy, array $attributes, User $actor, ?UploadedFile $cv = null): Applicant
    {
        app(VacancyService::class)->requireOpen($vacancy);

        return DB::transaction(function () use ($vacancy, $attributes, $actor, $cv) {
            $this->filingAgainst = $vacancy;

            try {
                /** @var Applicant $applicant */
                $applicant = $this->create($attributes, $actor);
            } finally {
                $this->filingAgainst = null;
            }

            $applicant->setRelation('vacancy', $vacancy);

            if ($cv !== null) {
                $this->attachCv($applicant, $cv, $actor);
            }

            return $applicant;
        });
    }

    /**
     * The vacancy the application belongs to, stamped before the insert.
     *
     * It is NOT in the payload and never can be — `vacancy_id` is in the deny list above, so a
     * hidden field naming somebody else's requisition is dropped (§84). It comes from the URL
     * segment the controller already authorised, and it is written here rather than afterwards
     * because the column is NOT NULL: an application with no requisition behind it is not a thing
     * this register is able to hold, and a two-step write would have to be able to hold one.
     */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        if ($this->filingAgainst === null) {
            throw new \DomainException('An application is always filed against a vacancy.');
        }

        $record->setAttribute('vacancy_id', $this->filingAgainst->getKey());
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Applicant $record */
        if ($record->isHired()) {
            throw new \DomainException('This applicant has been hired. Change the personnel record instead.');
        }

        return parent::update($record, $attributes, $actor);
    }

    // ================================================================== the CV

    public function attachCv(Applicant $applicant, UploadedFile $cv, User $actor): Applicant
    {
        $mime = (string) $cv->getMimeType();
        $extension = mb_strtolower($cv->getClientOriginalExtension());

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \DomainException('A CV must be a PDF, a Word document or a scan. That file appears to be '.$mime.'.');
        }

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \DomainException('That file type cannot be attached as a CV.');
        }

        if ($cv->getSize() > self::MAX_FILE_BYTES) {
            throw new \DomainException('A CV may be at most 8MB.');
        }

        $path = $cv->storeAs(
            'organizations/'.$applicant->organization_id.'/applicants/'.$applicant->getKey(),
            Str::uuid()->toString().'.'.$extension,
            'local',
        );

        $previous = $applicant->cv_path;

        $applicant->forceFill([
            'cv_path' => $path,
            'cv_name' => mb_substr($cv->getClientOriginalName(), 0, 255),
            'cv_mime' => $mime,
            'cv_size' => $cv->getSize(),
        ])->save();

        // Replaced, not orphaned: the row points at the new file and nothing references the old
        // one, so leaving it there is somebody's CV sitting in storage for ever unread and unowned.
        if (filled($previous)) {
            Storage::disk('local')->delete($previous);
        }

        // The NAME of the file, never its contents, and never the applicant's own details — an
        // activity entry is read by everybody who can see the record.
        $this->activity->record($applicant, 'cv_attached', $actor->name.' attached a CV to '.$this->label($applicant).'.', ['file_name' => $applicant->cv_name], $actor);

        return $applicant;
    }

    public function removeCv(Applicant $applicant, User $actor): Applicant
    {
        if (! $applicant->hasCv()) {
            return $applicant;
        }

        $path = $applicant->cv_path;

        $applicant->forceFill(['cv_path' => null, 'cv_name' => null, 'cv_mime' => null, 'cv_size' => null])->save();

        Storage::disk('local')->delete($path);

        $this->activity->record($applicant, 'cv_removed', $actor->name.' removed the CV from '.$this->label($applicant).'.', [], $actor);

        return $applicant;
    }

    // ================================================================== the process

    /**
     * Add a step to somebody's interview trail.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addStage(Applicant $applicant, array $attributes, User $actor): ApplicantStage
    {
        $this->requireLive($applicant, 'given another interview stage');

        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw new \DomainException('Give the stage a name.');
        }

        return DB::transaction(function () use ($applicant, $attributes, $actor, $name) {
            $stage = new ApplicantStage([
                'name' => mb_substr($name, 0, 120),
                'sequence' => (int) ($attributes['sequence'] ?? ($applicant->stages()->max('sequence') + 1)),
                'scheduled_at' => $attributes['scheduled_at'] ?? null,
                'interviewer_id' => $attributes['interviewer_id'] ?? null,
            ]);

            $stage->organization_id = $applicant->organization_id;
            $stage->applicant_id = $applicant->getKey();
            $stage->created_by = $actor->getKey();
            $stage->save();

            // Booking an interview is what moves somebody out of the pile, and it is a consequence
            // of the act rather than a button somebody presses afterwards and forgets.
            if ($applicant->statusKey() === 'applied') {
                $this->moveTo($applicant, 'screening');
                $applicant->save();
            }

            $this->activity->record($applicant, 'stage_added', $actor->name.' added the '.$stage->name.' stage.', [], $actor);

            return $stage;
        });
    }

    /**
     * Record what came of a stage.
     *
     * A FAILED STAGE DOES NOT REJECT ANYBODY. Turning somebody down is a separate decision with its
     * own reason, because companies routinely take people forward who did badly at one step, and a
     * system that closes the application automatically makes that impossible to record honestly.
     */
    public function recordStage(ApplicantStage $stage, string $outcome, ?int $score, ?string $comments, User $actor): ApplicantStage
    {
        if (! in_array($outcome, ApplicantStage::OUTCOMES, true)) {
            throw new \DomainException('That is not a stage outcome.');
        }

        if ($score !== null && ($score < 0 || $score > 5)) {
            throw new \DomainException('Score an interview from 0 to 5.');
        }

        $applicant = $stage->applicant;

        $this->requireLive($applicant, 'assessed');

        return DB::transaction(function () use ($stage, $applicant, $outcome, $score, $comments, $actor) {
            $stage->forceFill([
                'outcome' => $outcome,
                'score' => $score,
                'comments' => $comments,
                'recorded_at' => now(),
                'recorded_by' => $actor->getKey(),
            ])->save();

            if ($outcome === 'passed' && in_array($applicant->statusKey(), ['applied', 'screening'], true)) {
                $this->moveTo($applicant, 'interview');
                $applicant->save();
            }

            // The outcome, never the comments. What an interviewer wrote about somebody is read
            // under the applicant's own restricted classification, not through a timeline entry.
            $this->activity->record($applicant, 'stage_recorded', $actor->name.' recorded the '.$stage->name.' stage as '.$outcome.'.', ['outcome' => $outcome], $actor);

            return $stage;
        });
    }

    public function makeOffer(Applicant $applicant, User $actor): Applicant
    {
        $this->requireLive($applicant, 'offered a job');
        app(VacancyService::class)->requireOpen($applicant->vacancy);

        return DB::transaction(function () use ($applicant, $actor) {
            $applicant->forceFill(['offered_at' => now()]);
            $this->moveTo($applicant, 'offered');
            $applicant->updated_by = $actor->getKey();
            $applicant->save();

            $this->activity->record($applicant, 'offered', $actor->name.' made an offer to '.$this->label($applicant).'.', [], $actor);

            return $applicant;
        });
    }

    /** Turned down by the company, with a reason kept for the next time they apply. */
    public function reject(Applicant $applicant, string $reason, User $actor): Applicant
    {
        return $this->close($applicant, 'rejected', $reason, 'rejected', $actor);
    }

    /** Withdrew of their own accord — not the same fact, and not to be reported as a rejection. */
    public function withdraw(Applicant $applicant, ?string $reason, User $actor): Applicant
    {
        return $this->close($applicant, 'withdrawn', $reason, 'withdrew', $actor);
    }

    private function close(Applicant $applicant, string $status, ?string $reason, string $verb, User $actor): Applicant
    {
        $this->requireLive($applicant, 'closed');

        if ($status === 'rejected' && blank($reason)) {
            throw new \DomainException('Say why this applicant is not being taken forward.');
        }

        return DB::transaction(function () use ($applicant, $status, $reason, $verb, $actor) {
            $applicant->forceFill(['decided_at' => now(), 'decision_reason' => $reason]);
            $this->moveTo($applicant, $status);
            $applicant->updated_by = $actor->getKey();
            $applicant->save();

            // The FACT, not the reason. "Not taken forward" is visible to everybody who can see the
            // application; why is under the record's own classification (§68).
            $this->activity->record($applicant, $status, $this->label($applicant).' '.$verb.'.', [], $actor);

            return $applicant;
        });
    }

    // ================================================================== hiring

    /**
     * Take somebody on: write the personnel record, link it back, and count the head.
     *
     * @param  array<string,mixed>  $attributes  employee_no, hired_on, position, unit, supervisor…
     */
    public function hire(Applicant $applicant, array $attributes, User $actor): Employee
    {
        if ($applicant->isHired()) {
            throw new \DomainException('This applicant has already been hired as '.$applicant->hiredEmployee?->employee_no.'.');
        }

        if ($applicant->statusKey() !== 'offered') {
            throw new \DomainException('Make an offer before hiring somebody, so the record shows one was made.');
        }

        $employeeNo = trim((string) ($attributes['employee_no'] ?? ''));

        if ($employeeNo === '') {
            throw new \DomainException('Give the new starter a staff number.');
        }

        $hiredOn = $attributes['hired_on'] ?? null;

        if (blank($hiredOn)) {
            throw new \DomainException('Give the date they start.');
        }

        return DB::transaction(function () use ($applicant, $attributes, $actor, $employeeNo, $hiredOn) {
            // Locked, and re-read inside the lock. Two clicks a second apart would otherwise both
            // see an unhired applicant and write two people into the personnel register.
            $vacancy = Vacancy::query()->lockForUpdate()->findOrFail($applicant->vacancy_id);
            $fresh = Applicant::query()->lockForUpdate()->findOrFail($applicant->getKey());

            if ($fresh->hired_employee_id !== null) {
                throw new \DomainException('This applicant has already been hired.');
            }

            app(VacancyService::class)->requireOpen($vacancy);

            $taken = Employee::query()
                ->where('organization_id', $applicant->organization_id)
                ->where('employee_no', $employeeNo)
                ->withTrashed()
                ->exists();

            if ($taken) {
                throw new \DomainException('Staff number '.$employeeNo.' is already in use.');
            }

            $employee = new Employee([
                'employee_no' => $employeeNo,
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'personal_email' => $applicant->email,
                'personal_phone' => $applicant->phone,
                'org_unit_id' => $attributes['org_unit_id'] ?? $vacancy->org_unit_id,
                'position_id' => $attributes['position_id'] ?? $vacancy->position_id,
                'location_id' => $attributes['location_id'] ?? $vacancy->location_id,
                'supervisor_id' => $attributes['supervisor_id'] ?? null,
                'employment_type' => $attributes['employment_type'] ?? ($vacancy->employment_type ?? 'full_time'),
                'employment_status' => $attributes['employment_status'] ?? 'probation',
                'hired_on' => $hiredOn,
                'probation_ends_on' => $attributes['probation_ends_on'] ?? null,
            ]);

            // Never from the payload (§74), and no `user_id` — see the class docblock.
            $employee->organization_id = $applicant->organization_id;
            $employee->save();

            $applicant->forceFill(['hired_employee_id' => $employee->getKey(), 'decided_at' => now()]);
            $this->moveTo($applicant, 'hired');
            $applicant->updated_by = $actor->getKey();
            $applicant->save();

            app(VacancyService::class)->recordHire($vacancy, $actor);

            $this->activity->record($applicant, 'hired', $actor->name.' hired '.$applicant->fullName().' as '.$employeeNo.'.', ['employee_no' => $employeeNo], $actor);

            return $employee;
        });
    }

    // ================================================================== helpers

    private function requireLive(Applicant $applicant, string $doing): void
    {
        if (! in_array($applicant->statusKey(), self::LIVE, true)) {
            throw new \DomainException('This application is closed, so it cannot be '.$doing.'.');
        }
    }
}
