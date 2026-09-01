<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IncidentPerson;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Add or amend somebody caught up in an incident.
 *
 * `person_name` IS REQUIRED AND `user_id` IS NOT, which is the right way round. Most people hurt on
 * a construction site have no login: they work for a subcontractor, they are a delivery driver, they
 * are a member of the public. A form that could only record employees would quietly drop the
 * incidents that matter most, so the name is the fact and the account is the optional link.
 *
 * THE MEDICAL HALF IS SPLIT OUT OF `validated()` ON PURPOSE. `personAttributes()` takes a flag, and
 * the controller passes the result of a `viewMedical` policy check rather than anything from the
 * request. A person who may record that Ada was a witness must not be able to record — or silently
 * overwrite — an injury by posting extra fields, and hiding those inputs in the form is a courtesy,
 * not a control (§6, §84).
 *
 * NOT ACCEPTED: `incident_id` (it comes from the bound route model, never the body), or
 * `organization_id`.
 */
class IncidentPersonRequest extends TenantRequest
{
    /** The fields that only somebody holding `hse_incident.view_confidential` may write. */
    public const MEDICAL_FIELDS = [
        'injury_type', 'body_part', 'treatment', 'days_lost', 'returned_to_work_on', 'notes',
    ];

    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'user_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'person_name' => ['required', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            'occupation' => ['nullable', 'string', 'max:120'],

            'involvement' => [
                'nullable',
                Rule::in($lists->keys('hse_incident.involvements', IncidentPerson::DEFAULT_INVOLVEMENTS)),
            ],

            // Nullable throughout. A witness has no injury, and a row that demanded one would be
            // filled in with nonsense that later reads as data.
            'injury_type' => ['nullable', 'string', 'max:120'],
            'body_part' => ['nullable', 'string', 'max:120'],
            'treatment' => [
                'nullable',
                Rule::in($lists->keys('hse_incident.treatments', IncidentPerson::DEFAULT_TREATMENTS)),
            ],
            'days_lost' => ['nullable', 'integer', 'between:0,3650'],
            'returned_to_work_on' => ['nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'user_id', 'company', 'occupation', 'involvement', 'injury_type', 'body_part',
            'treatment', 'days_lost', 'returned_to_work_on', 'notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'person_name.required' => 'Give the person a name. Most people hurt on a site do not have an account here.',
        ];
    }

    /**
     * @param  bool  $includeMedical  the result of a `viewMedical` policy check, never a request value
     * @return array<string,mixed>
     */
    public function personAttributes(bool $includeMedical): array
    {
        $data = $this->validated();

        return $includeMedical
            ? $data
            : array_diff_key($data, array_flip(self::MEDICAL_FIELDS));
    }
}
