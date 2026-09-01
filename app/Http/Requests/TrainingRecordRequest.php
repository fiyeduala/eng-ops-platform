<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TrainingRecord;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * File a certificate.
 *
 * `expires_on` IS ACCEPTED EVEN THOUGH THE SERVICE CAN WORK IT OUT. TrainingRecordService computes
 * it from the course's validity when the field is left blank, and takes what is typed when it is
 * not — because the date on the card wins. Certificates are issued by external bodies whose
 * validity rules change, are extended by grace periods and are occasionally just printed with a
 * different date, and a register that insisted on its own arithmetic would be wrong about the one
 * fact it exists to hold.
 *
 * IT IS ALSO NOT PROTECTED AFTER CREATION, unlike almost every other outcome column in this phase.
 * An expiry is a date copied off a card, not a workflow outcome, so a typo has to be correctable
 * by the person who made it rather than requiring a new record.
 *
 * `user_id` OR `person_name`, AND AT LEAST ONE. The service fills the name in from the linked
 * account when one is given and refuses a record with neither, because a certificate that names
 * nobody is a certificate nobody can produce when it is asked for.
 *
 * NOT ACCEPTED: `number`, `status_id`, `organization_id`.
 */
class TrainingRecordRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'training_course_id' => ['nullable', 'integer', $this->existsInTenant('training_courses')],

            'user_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'person_name' => ['nullable', 'string', 'max:200', 'required_without:user_id'],
            'company' => ['nullable', 'string', 'max:200'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'completed_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:completed_on'],

            'certificate_number' => ['nullable', 'string', 'max:120'],

            // The scan of the certificate lives in document control, behind private storage and the
            // same authorization every other file in the application obeys (§67).
            'document_id' => ['nullable', 'integer', $this->existsInTenant('documents')],

            'result' => [
                'nullable',
                Rule::in(app(OptionList::class)->keys('hse_training.results', TrainingRecord::DEFAULT_RESULTS)),
            ],
            'score' => ['nullable', 'numeric', 'between:0,9999'],

            'provider' => ['nullable', 'string', 'max:200'],
            'trainer_name' => ['nullable', 'string', 'max:200'],

            // No currency column and no currency symbol anywhere on this record. The company's own
            // books say what the figure is in, and a symbol this platform guessed would be worse
            // than none (§47).
            'cost' => ['nullable', 'numeric', 'between:0,999999999999.99'],

            'notes' => ['nullable', 'string', 'max:10000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'training_course_id', 'user_id', 'person_name', 'company', 'project_id', 'org_unit_id',
            'completed_on', 'expires_on', 'certificate_number', 'document_id', 'result', 'score',
            'provider', 'trainer_name', 'cost', 'notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'person_name.required_without' => 'Name the person, or link the account this certificate belongs to.',
            'expires_on.after_or_equal' => 'A certificate cannot expire before it was earned.',
        ];
    }

    /** @return array<string,mixed> */
    public function trainingAttributes(): array
    {
        return $this->validated();
    }
}
