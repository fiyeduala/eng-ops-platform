<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Correcting a balance. Only the three granted figures and a note — never days used, which are
 * counted from the requests.
 */
class LeaveBalanceRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'entitled' => ['required', 'numeric', 'min:0', 'max:366'],
            'carried_over' => ['required', 'numeric', 'min:0', 'max:366'],
            'adjustment' => ['required', 'numeric', 'min:-366', 'max:366'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['notes']);
    }
}
