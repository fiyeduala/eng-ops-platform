<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A vacancy requisition. The requester, the status, the number and the hired count are never on the
 * form — the first is the actor, the rest are written by the service and the workflow engine (§84).
 */
class VacancyRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            // Every foreign key goes through existsInTenant, so another company's department id
            // cannot be stitched onto our row by a hidden field (§75, §84).
            'position_id' => ['nullable', 'integer', $this->existsInTenant('positions')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'hiring_manager_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'employment_type' => ['nullable', 'string', 'max:40'],
            'openings' => ['required', 'integer', 'min:1', 'max:999'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'target_start_date' => ['nullable', 'date'],
            'closes_on' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'position_id', 'org_unit_id', 'location_id', 'project_id',
            'hiring_manager_id', 'employment_type', 'target_start_date', 'closes_on',
        ]);
    }
}
