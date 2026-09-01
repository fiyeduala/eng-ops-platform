<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Investigation;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Write up why it happened.
 *
 * `incident_id` IS NOT ACCEPTED. An investigation is opened against an incident by
 * InvestigationService::openFor(), which the `create_record` workflow auto-action calls, and the
 * incident comes from the bound route model on the manual path. Accepting it in the body would let
 * somebody re-point a finished investigation at a different incident — rewriting, in one POST, both
 * what was investigated and what the conclusion was about (§84).
 *
 * IMMEDIATE CAUSE AND ROOT CAUSE ARE TWO FIELDS AND THE FORM ASKS FOR BOTH. "The operative did not
 * clip on" is an immediate cause; an investigation that stops there produces a corrective action
 * that says "remind operatives to clip on", and the same incident happens again next quarter. The
 * second box is the one that does the work, which is why the help text on it is the longest on the
 * form and why neither is required — an honest blank is better than a copied sentence.
 *
 * IT DEFAULTS TO RESTRICTED because an investigation quotes the medical detail it was opened over.
 * The column default in the migration does that; this form simply does not offer a way to make one
 * public by accident on the day it is created.
 *
 * NOT ACCEPTED: `number`, `status_id`, `completed_on` (set by `complete()`), `organization_id`.
 */
class InvestigationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'lead_id' => ['nullable', 'integer', $this->existsInTenant('users')],

            'method' => [
                'nullable',
                Rule::in(app(OptionList::class)->keys('investigation.methods', Investigation::DEFAULT_METHODS)),
            ],

            'started_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:started_on'],

            'sequence_of_events' => ['nullable', 'string', 'max:20000'],
            'immediate_cause' => ['nullable', 'string', 'max:10000'],
            'root_cause' => ['nullable', 'string', 'max:10000'],
            'contributing_factors' => ['nullable', 'string', 'max:10000'],
            'findings' => ['nullable', 'string', 'max:20000'],
            'recommendations' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'project_id', 'discipline_id', 'org_unit_id', 'lead_id', 'method', 'started_on',
            'due_on', 'sequence_of_events', 'immediate_cause', 'root_cause',
            'contributing_factors', 'findings', 'recommendations',
        ]);
    }

    public function messages(): array
    {
        return [
            'due_on.after_or_equal' => 'An investigation cannot be due before it started.',
        ];
    }

    /** @return array<string,mixed> */
    public function investigationAttributes(): array
    {
        return $this->validated();
    }
}
