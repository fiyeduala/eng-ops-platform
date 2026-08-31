<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ChangeRequest;
use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Raise or amend an engineering change request.
 *
 * THE IMPACT IS THE RECORD. A change request whose cost and schedule columns are blank is a note
 * somebody wrote; the whole point is a number a decision-maker can weigh. They are nullable rather
 * than required because the impact is genuinely unknown on the day the change is first raised —
 * but the form asks for them, and `decide()` refuses a rejection or a deferral with no notes, so
 * the record cannot reach a decision with nothing on it that explains one.
 *
 * COST IMPACT IS A PLAIN DECIMAL AND MAY BE NEGATIVE. A change that saves money is still a change,
 * and a rule of `min:0` would force somebody to record a saving as a cost — which is how a project
 * ends up reporting a change budget it never spent. There is no currency column: the platform does
 * not price work, and §47 forbids baking in country-specific financial assumptions. The company's
 * own currency is the one on its books.
 *
 * NOT ACCEPTED: `number`, `status_id`, `disposition`, `decided_by`, `decided_at`,
 * `decision_notes`, `organization_id`. The disposition is the decision, it has its own permission
 * and its own endpoint, and that endpoint refuses to let the author decide their own request.
 */
class ChangeRequestRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],

            // Required, unlike most descriptions in this application. A change request is read by
            // people who were not in the room — a title alone cannot be decided on.
            'description' => ['required', 'string', 'max:20000'],
            'reason' => ['nullable', 'string', 'max:5000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'origin' => ['required', Rule::in(array_keys(ChangeRequest::ORIGINS))],

            'cost_impact' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'schedule_impact_days' => ['nullable', 'integer', 'between:-3650,3650'],
            'impact_notes' => ['nullable', 'string', 'max:5000'],

            'raised_by' => ['nullable', 'integer', $this->existsInTenant('users')],
            'raised_on' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:raised_on'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'reason', 'project_id', 'discipline_id', 'org_unit_id', 'cost_impact',
            'schedule_impact_days', 'impact_notes', 'raised_by', 'raised_on', 'required_by',
        ]);
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Describe the change. Somebody who was not in the room has to be able to decide on it.',
            'required_by.after_or_equal' => 'A decision cannot be due before the change was raised.',
        ];
    }

    /** @return array<string,mixed> */
    public function changeRequestAttributes(): array
    {
        return $this->validated();
    }
}
