<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DisciplinaryCase;
use Illuminate\Validation\Rule;

/**
 * Opening a conduct case: who, when, what is alleged, and how serious it is said to be.
 *
 * SEVERITY IS A FIXED LIST and category is not. Severity drives how the record is handled — minor,
 * serious, gross — and three words the platform understands are worth more than free text nobody can
 * report on. What the case is ABOUT differs by company and by handbook, so `category` is offered as
 * company data with a default list (§47).
 *
 * THE OUTCOME IS NOT HERE. Nor the investigator, the hearing notes or the sanction: each is written
 * by its own act, behind its own permission, in its own request. A form that could post `outcome`
 * would be a way to sanction somebody without a hearing (§84).
 *
 * WHO REPORTED IT IS NOT A FIELD. It is the signed-in user, stamped by the service.
 */
class DisciplinaryCaseRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', $this->existsInTenant('employees')],
            'category' => ['required', 'string', 'max:40'],
            'severity' => ['required', Rule::in(DisciplinaryCase::SEVERITIES)],
            'incident_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reported_on' => ['nullable', 'date', 'before_or_equal:today'],
            // The allegation itself. Required, because a case with no account of what happened is
            // not a case — it is a name on a list.
            'summary' => ['required', 'string', 'max:10000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['incident_on', 'reported_on']);
    }
}
