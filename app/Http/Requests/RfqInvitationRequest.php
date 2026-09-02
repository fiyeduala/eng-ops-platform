<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Put one supplier on an enquiry's invitation list.
 *
 * ONE AT A TIME, AND THAT IS DELIBERATE. A multi-select would have to reimplement the two rules
 * RfqService::invite() applies — that a blacklisted or suspended company is refused WITH THE REASON,
 * and that a supplier already on the list is not duplicated — and would then have to report a
 * partial failure across six names. Adding them one at a time makes every refusal legible.
 *
 * The id is validated as a row of this tenant here and resolved through the tenant-scoped query in
 * the service; another organization's supplier is not found rather than refused, so the field
 * cannot be used to discover which ids exist elsewhere (§75, §61).
 */
class RfqInvitationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', $this->existsInTenant('vendors')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Choose the supplier to invite.',
            'vendor_id.exists' => 'That supplier could not be found.',
        ];
    }
}
