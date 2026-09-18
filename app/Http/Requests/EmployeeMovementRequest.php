<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A promotion, transfer, secondment, acting appointment or regrade.
 *
 * THE KIND IS COMPANY DATA. `EmployeeMovement::DEFAULT_KINDS` is a starting list, not a closed one —
 * a company with "redeployment" or "conversion" in its handbook is describing something real, and a
 * fixed enum here would make the platform argue with its own users (§47). The rule checks that
 * something was chosen and that it is short enough to read in a column.
 *
 * THE "FROM" SIDE IS NOT A FIELD. It is a snapshot the service takes off the personnel record at
 * submission, because a history that reads today's employee row is not a history (§84).
 *
 * PAY IS NOT A FIELD EITHER. A promotion changes a salary, and salaries live in payroll behind their
 * own permission — a movement form with a figure on it would put every salary in the company inside
 * a register department heads can read.
 */
class EmployeeMovementRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', $this->existsInTenant('employees')],
            'kind' => ['required', 'string', 'max:40'],

            'to_position_id' => ['nullable', 'integer', $this->existsInTenant('positions')],
            'to_org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'to_location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],
            'to_supervisor_id' => ['nullable', 'integer', $this->existsInTenant('employees')],

            'effective_on' => ['required', 'date'],
            // Secondments and acting appointments end; promotions do not. Optional, and after the
            // day it starts when it is given at all.
            'ends_on' => ['nullable', 'date', 'after_or_equal:effective_on'],

            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'to_position_id', 'to_org_unit_id', 'to_location_id', 'to_supervisor_id', 'ends_on', 'notes',
        ]);
    }
}
