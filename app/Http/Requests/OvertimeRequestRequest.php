<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * An overtime request, asked for before the hours are worked. The requester, unit and status are
 * never on the form; the hours are checked for shape again in the service.
 */
class OvertimeRequestRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', $this->existsInTenant('employees')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'work_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['employee_id', 'project_id']);
    }
}
