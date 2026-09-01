<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RiskAssessment;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Write or amend a risk assessment.
 *
 * `valid_to` IS THE MOST IMPORTANT FIELD ON THE FORM. An assessment that lapsed four months ago is
 * worse than no assessment at all, because the work carried on under it and everybody believed it
 * was covered. The register opens on "expiring", the field is asked for prominently, and the
 * company's default validity is offered as a starting point — but never imposed, because a
 * confined-space entry assessment and an office DSE assessment do not expire on the same cycle.
 *
 * ASSESSED, REVIEWED AND APPROVED ARE THREE PEOPLE AND ONLY THE FIRST IS ON THIS FORM. Review and
 * approval are separate endpoints behind separate permissions, and RiskAssessmentService refuses to
 * let one person do both. This is the one HSE record where separation of duties is a legal
 * expectation rather than a house rule, and a form that accepted `approved_by` would quietly undo
 * it (§84).
 *
 * THE HAZARDS ARE NOT HERE EITHER. They are a table, edited as a table, through
 * RiskAssessmentHazardsRequest — see that class for why they are replaced wholesale rather than
 * patched row by row.
 *
 * NOT ACCEPTED: `number`, `status_id`, `reviewed_*`, `approved_*`, `organization_id`.
 */
class RiskAssessmentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'assessment_type' => [
                'nullable',
                Rule::in(app(OptionList::class)->keys('risk_assessment.types', RiskAssessment::DEFAULT_TYPES)),
            ],

            // The activity being assessed, which is not the same as the title: "Working at height"
            // is a title, "Replacing cladding panels on the east elevation" is the activity, and an
            // assessment that only records the first is a generic document nobody reads on site.
            'activity' => ['nullable', 'string', 'max:300'],
            'location' => ['nullable', 'string', 'max:200'],

            'assessed_by' => ['nullable', 'integer', $this->existsInTenant('users')],
            'assessed_on' => ['nullable', 'date'],

            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'review_frequency_months' => ['nullable', 'integer', 'between:1,60'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'project_id', 'discipline_id', 'org_unit_id', 'assessment_type',
            'activity', 'location', 'assessed_by', 'assessed_on', 'valid_from', 'valid_to',
            'review_frequency_months',
        ]);
    }

    public function messages(): array
    {
        return [
            'valid_to.after_or_equal' => 'An assessment cannot expire before it takes effect.',
        ];
    }

    /** @return array<string,mixed> */
    public function assessmentAttributes(): array
    {
        return $this->validated();
    }
}
