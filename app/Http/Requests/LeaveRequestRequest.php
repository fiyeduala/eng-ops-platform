<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A leave request. The day count, the requester, the unit and the status are never on the form —
 * the service works them out from the dates and the personnel record.
 */
class LeaveRequestRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', $this->existsInTenant('employees')],
            'leave_type_id' => ['required', 'integer', $this->existsInTenant('leave_types', activeOnly: true)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_half_day' => ['boolean'],
            'end_half_day' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'handover_to' => ['nullable', 'integer', $this->existsInTenant('users')],
            'contact_while_away' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['employee_id', 'reason', 'handover_to', 'contact_while_away']);
        $this->normaliseBooleans(['start_half_day', 'end_half_day']);
    }
}
