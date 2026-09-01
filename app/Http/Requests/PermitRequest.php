<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Request a permit to work, or amend one not yet issued.
 *
 * A PERMIT IS A WINDOW OF TIME AND THAT IS WHY BOTH DATES ARE DATETIMES. Permits are worked to in
 * hours, not days: a hot-work permit valid "on Tuesday" is not a permit, it is a note. The type's
 * default duration fills `valid_to` in when it is left blank, which is what most people want and
 * nobody is forced to accept.
 *
 * ISSUING IS NOT ON THIS FORM, AND NEITHER IS ANYTHING ELSE THAT HAPPENS TO A LIVE PERMIT. Issue,
 * accept, suspend, resume, close and cancel are six endpoints behind six permissions, because each
 * is an act somebody performs and is answerable for — the permit-to-work system is the one place in
 * this application where a form field standing in for a signature would be dangerous rather than
 * merely sloppy (§84). PermitService protects every one of those columns.
 *
 * THE GAS TEST IS NOT HERE EITHER. `gas_test_required` is copied from the permit type at creation
 * so the permit stays readable on its own terms after the type is edited, and the RESULT is
 * recorded by whoever holds the meter, through `recordGasTest()`.
 *
 * NOT ACCEPTED: `number`, `status_id`, `requested_by`, `issued_*`, `accepted_*`, `suspended_*`,
 * `closed_*`, `cancelled_*`, `gas_test_*`, `organization_id`.
 */
class PermitRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            // Nullable rather than required: a company that has not configured its permit types yet
            // must still be able to raise a permit, and an untyped permit with a clear scope is
            // better than a typed one filed under whichever entry happened to exist.
            'permit_type_id' => ['nullable', 'integer', $this->existsInTenant('permit_types', 'id', true)],

            'title' => ['required', 'string', 'max:300'],

            // Required. A permit describes work that may kill somebody if it is misunderstood, and
            // this is the field the issuer reads before signing.
            'description' => ['required', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'location' => ['required', 'string', 'max:200'],
            'equipment' => ['nullable', 'string', 'max:200'],
            'worker_count' => ['nullable', 'integer', 'between:1,2000'],

            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],

            'isolation_reference' => ['nullable', 'string', 'max:120'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'permit_type_id', 'project_id', 'discipline_id', 'org_unit_id', 'equipment',
            'worker_count', 'valid_from', 'valid_to', 'isolation_reference',
        ]);
    }

    public function messages(): array
    {
        return [
            'location.required' => 'Say where the work is. A permit that does not name a place cannot be checked by anybody walking past.',
            'description.required' => 'Describe the work. Whoever issues this permit signs against this field.',
            'valid_to.after' => 'A permit has to be valid for some length of time.',
        ];
    }

    /** @return array<string,mixed> */
    public function permitAttributes(): array
    {
        return $this->validated();
    }
}
