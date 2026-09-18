<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Somebody leaving. The status, the clearance and the revocation timestamp are written by the
 * service after its own checks and are never on the form (§84).
 *
 * `reason` IS FREE OF A FIXED LIST HERE. Why somebody leaves differs by country and by contract —
 * "redundancy" means something specific in one jurisdiction and nothing in another — so the list is
 * offered as company data and the request checks only that something was said (§47).
 */
class EmployeeExitRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', $this->existsInTenant('employees')],
            'reason' => ['required', 'string', 'max:40'],
            'notified_on' => ['nullable', 'date'],
            'last_working_day' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'interview_notes' => ['nullable', 'string', 'max:5000'],
            'eligible_for_rehire' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['notified_on', 'notes', 'interview_notes']);
    }
}
