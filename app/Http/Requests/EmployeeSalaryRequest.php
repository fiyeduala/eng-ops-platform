<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmployeeSalary;
use Illuminate\Validation\Rule;

/**
 * Putting somebody on a salary from a date.
 *
 * `hours_per_period` IS OPTIONAL AND ITS ABSENCE IS MEANINGFUL. It is the divisor that prices
 * overtime, and a company that has not agreed one has not agreed one — the calculator drops the
 * overtime line rather than inventing a rate, which is visible on the payslip and gets asked about.
 * Defaulting it to 173.33 here, as payroll systems habitually do, would pay everybody's overtime at
 * a figure nobody chose and nobody would ever notice.
 *
 * THE EMPLOYEE IS NOT A FIELD. It comes from the route, and the controller re-authorizes it against
 * the actor's own reach: being allowed to set salaries is not being allowed to set anybody's (§84).
 */
class EmployeeSalaryRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'basic_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'frequency' => ['required', Rule::in(array_keys(EmployeeSalary::FREQUENCIES))],
            'effective_from' => ['required', 'date'],
            // Almost always blank. A salary runs until the next one supersedes it, and the service
            // closes the previous row itself — an end date is for a genuinely fixed-term arrangement.
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'hours_per_period' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['effective_to', 'hours_per_period', 'reason', 'notes']);

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper(trim($this->input('currency')))]);
        }
    }
}
