<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Giving one person a pay component the rest of the company does not have.
 *
 * A car loan repayment, a housing allowance agreed for one role, a court-ordered deduction. The
 * component itself already exists in the pay structure; this row says who has it, from when, and —
 * occasionally — on what terms.
 *
 * THE OVERRIDES ARE NULLABLE AND THAT IS THE COMMON CASE. Blank means "use the component's own
 * figure", so the ordinary assignment says only that this person has this component at all. An
 * override is for the genuinely individual case: this person's loan is repaid at this amount a
 * month, because that is what they signed.
 *
 * THE EMPLOYEE IS NOT A FIELD. It comes from the route and the controller re-authorizes it — being
 * allowed to assign components is not being allowed to assign them to anybody (§84).
 */
class EmployeePayComponentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'pay_component_id' => ['required', 'integer', $this->existsInTenant('pay_components')],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            // Blank for something open-ended. A loan, by contrast, ends — and setting the end date
            // when it is agreed is what stops it being deducted for a year after it was repaid.
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['amount', 'rate', 'effective_to', 'reference', 'notes']);

        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
