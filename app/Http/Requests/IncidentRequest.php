<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Report an incident, or correct one already reported.
 *
 * ALMOST NOTHING IS REQUIRED, AND THAT IS DELIBERATE. The person filling this in is frequently
 * standing on a site at eleven at night with a phone in one hand, and every mandatory field is one
 * more reason to close the form and "do it properly tomorrow" — which is how the near-miss half of
 * a register ends up empty. A title and the fact that something happened is enough to open a
 * record; the investigation is what fills the rest in.
 *
 * `occurred_at` AND `reported_at` ARE BOTH ACCEPTED AND NEITHER DEFAULTS TO THE OTHER. The gap
 * between them is one of the few numbers a regulator actually asks for, and a form that quietly set
 * the occurrence time to "now" would destroy the only evidence that a report was late.
 *
 * THE STATUTORY FIELDS ARE NOT HERE. `is_reportable`, `reported_to_authority_on` and
 * `authority_reference` are written by IncidentService::markReportable() behind
 * `hse_incident.investigate`, because deciding that an incident is legally reportable is an act
 * somebody performs and is accountable for, not a checkbox on an edit form (§84).
 *
 * NEITHER IS ANYTHING ABOUT A PERSON. Who was hurt, what was injured and how many days were lost
 * live on `incident_persons` behind `hse_incident.view_confidential`. Accepting them here would put
 * medical detail into a form anybody who can edit the register can open (§68).
 *
 * NOT ACCEPTED: `number`, `status_id`, `closed_at`, `closed_by`, `organization_id`.
 */
class IncidentRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        $categories = $lists->keys('hse_incident.categories', Incident::DEFAULT_CATEGORIES);
        $severities = $lists->keys('hse_incident.severities', Incident::DEFAULT_SEVERITIES);

        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'category' => ['nullable', Rule::in($categories)],

            // Both against the SAME list. See Incident::DEFAULT_SEVERITIES — two scales could not
            // be compared, and comparing them is the entire argument for near-miss reporting.
            'severity' => ['nullable', Rule::in($severities)],
            'potential_severity' => ['nullable', Rule::in($severities)],

            'location' => ['nullable', 'string', 'max:200'],

            // No `before_or_equal:now`. Clock skew on a phone, a site running on local time and a
            // record typed up the following morning all produce a legitimate few minutes in the
            // future, and refusing them would teach people to type a round number instead.
            'occurred_at' => ['nullable', 'date'],
            'reported_at' => ['nullable', 'date', 'after_or_equal:occurred_at'],
            'reported_by' => ['nullable', 'integer', $this->existsInTenant('users')],

            'immediate_actions' => ['nullable', 'string', 'max:10000'],

            // Lost time is an operational fact about the event, not a medical one — "the job stopped
            // for three days" is on the incident; "Ada was off for three days" is on her row.
            'is_lost_time' => ['boolean'],
            'lost_time_days' => ['nullable', 'integer', 'between:0,3650'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_lost_time']);

        $this->nullifyBlanks([
            'description', 'project_id', 'discipline_id', 'org_unit_id', 'category', 'severity',
            'potential_severity', 'location', 'occurred_at', 'reported_at', 'reported_by',
            'immediate_actions', 'lost_time_days',
        ]);
    }

    public function messages(): array
    {
        return [
            'reported_at.after_or_equal' => 'An incident cannot have been reported before it happened.',
        ];
    }

    /** @return array<string,mixed> */
    public function incidentAttributes(): array
    {
        return $this->validated();
    }
}
