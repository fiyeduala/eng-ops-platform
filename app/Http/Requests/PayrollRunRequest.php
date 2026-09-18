<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PayrollRun;
use Illuminate\Validation\Rule;

/**
 * Opening or renaming a payroll run.
 *
 * ONLY THE PERIOD IS A FIELD. There is no gross, no net, no payslip count and no status here — every
 * figure on a run is produced by calculating it, and the service strips all of them from input
 * anyway (§84). A form that could post a net total would be a form that could pay somebody a number
 * nobody calculated.
 *
 * THE CURRENCY IS NOT TAKEN FROM THE FORM EITHER in any meaningful sense: it is validated as three
 * letters, but a run whose currency does not match a person's salary skips that person with a reason
 * rather than converting. Inventing an exchange rate to pay somebody would be the platform making up
 * a figure that nobody agreed and that the employee cannot check.
 */
class PayrollRunRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'period_start' => ['required', 'date'],
            // A period that ends before it starts would produce a negative pro-rata for everybody,
            // and the arithmetic would run perfectly well and pay nobody anything.
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Usually on or after the period ends, but not required to be: companies pay in advance,
            // and a platform that refused would be arguing with a real payroll calendar.
            'pay_date' => ['nullable', 'date'],
            'frequency' => ['required', 'string', Rule::in(array_keys(PayrollRun::FREQUENCIES))],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['pay_date', 'notes']);

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper(trim($this->input('currency')))]);
        }
    }
}
