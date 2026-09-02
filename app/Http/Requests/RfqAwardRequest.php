<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Decide an enquiry.
 *
 * THE REASON IS CONDITIONALLY REQUIRED AND THE CONDITION IS NOT ON THE FORM. Whether this award
 * needs a written justification depends on how many quotations came back and on
 * `procurement.minimum_quotations`, both of which are server-side facts — so RfqService::award()
 * makes that call and throws with a sentence naming the numbers. Validating it here as well would
 * mean two places deciding, and the form would be the one that could be lied to (§84).
 *
 * What this class is for is the shape: a quotation id, and prose short enough to store. That the
 * quotation was received against THIS enquiry is checked in the service, because only a scoped
 * lookup can answer it.
 */
class RfqAwardRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'quotation_id' => ['required', 'integer', $this->existsInTenant('quotations')],
            'reason' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'quotation_id.required' => 'Choose the quotation being awarded.',
            'quotation_id.exists' => 'That quotation could not be found.',
        ];
    }
}
