<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Plan a toolbox talk, or correct one already planned.
 *
 * THE ATTENDANCE LIST IS NOT ON THIS FORM. It arrives with `record()`, at the moment somebody says
 * the talk was delivered, because an attendance list typed in advance is a prediction and an
 * attendance list typed afterwards is a record. The distinction matters the day somebody has to
 * prove that a particular person was briefed on a particular hazard before a particular accident.
 *
 * `held_at` IS NOT ACCEPTED HERE EITHER — it is stamped by `record()`. A talk whose delivery date
 * could be edited on an ordinary form is a talk whose evidence is worth nothing, and
 * ToolboxTalkService lists it as a protected attribute for exactly that reason.
 *
 * `presenter_name` EXISTS ALONGSIDE `presented_by` because the person who gives the briefing is
 * frequently a subcontractor's supervisor with no login here.
 *
 * NOT ACCEPTED: `number`, `status_id`, `held_at`, `presented_by`, `organization_id`.
 */
class ToolboxTalkRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'topic' => ['nullable', 'string', 'max:200'],

            // What was actually said. Kept as text on the record rather than only as an attached
            // document, so that a talk delivered from a phone on a wet morning still has content.
            'content' => ['nullable', 'string', 'max:20000'],
            'key_points' => ['nullable', 'string', 'max:10000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'location' => ['nullable', 'string', 'max:200'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,480'],

            'presenter_name' => ['nullable', 'string', 'max:200'],

            // The briefing sheet lives in document control, under the same private-storage and
            // authorization rules as every other file in the application (§67).
            'document_id' => ['nullable', 'integer', $this->existsInTenant('documents')],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'topic', 'content', 'key_points', 'project_id', 'discipline_id', 'org_unit_id',
            'location', 'duration_minutes', 'presenter_name', 'document_id',
        ]);
    }

    /** @return array<string,mixed> */
    public function talkAttributes(): array
    {
        return $this->validated();
    }
}
