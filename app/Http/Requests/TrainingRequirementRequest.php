<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TrainingRequirement;
use Illuminate\Validation\Rule;

/**
 * "This job needs this course."
 *
 * The scope decides which target matters, and the service clears the others — the rule here only
 * has to make sure the one that matters was chosen. Both targets are validated through
 * existsInTenant(), because a position id in a form is user input like any other (§84).
 */
class TrainingRequirementRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'training_course_id' => ['required', 'integer', $this->existsInTenant('training_courses')],
            'scope' => ['required', Rule::in(TrainingRequirement::SCOPES)],
            'org_unit_id' => [
                Rule::requiredIf(fn () => $this->input('scope') === TrainingRequirement::SCOPE_ORG_UNIT),
                'nullable', 'integer', $this->existsInTenant('org_units'),
            ],
            'position_id' => [
                Rule::requiredIf(fn () => $this->input('scope') === TrainingRequirement::SCOPE_POSITION),
                'nullable', 'integer', $this->existsInTenant('positions'),
            ],
            'is_mandatory' => ['nullable', 'boolean'],
            // A grace period is how long somebody may keep working on a lapsed ticket while the
            // refresher is booked. Company data, with a sane ceiling rather than a policy (§47).
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_mandatory', 'is_active']);
        $this->nullifyBlanks(['org_unit_id', 'position_id', 'notes']);
    }
}
