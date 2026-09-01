<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Client;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Add a client, or correct one already on the register.
 *
 * SHORTER THAN VendorRequest, AND THAT ASYMMETRY IS THE POINT. Nothing on this form can redirect a
 * payment, bar a company from bidding, or invent a performance score. A client record is a name, a
 * way to reach them, and the projects hanging off it — so it is validated like the contact record
 * it is rather than fenced like the financial instrument a vendor is.
 *
 * `relationship_owner_id` IS NOT ACCEPTED HERE even though it is an ordinary-looking select. It
 * decides WHO CAN SEE THE CLIENT: it is the column Client::accessAssigneeColumns() reads, so a user
 * restricted to their own assignments widens their own reach by naming themselves. It moves through
 * ClientController::assignOwner(), gated on `client.manage` — the same separation ClientPolicy
 * argues for from the other side.
 *
 * NOT ACCEPTED: `number`, `status_id`, `relationship_owner_id`, `organization_id`, `created_by`.
 */
class ClientRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],

            'code' => ['nullable', 'string', 'max:50', $this->uniqueInTenant('clients', 'code', $this->route('client')?->id)],

            'client_type' => ['nullable', Rule::in($lists->keys('client.types', Client::DEFAULT_TYPES))],
            'industry' => ['nullable', Rule::in($lists->keys('client.industries', Client::DEFAULT_INDUSTRIES))],

            'registration_no' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],

            'email' => ['nullable', 'email:rfc', 'max:200'],
            'phone' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'url', 'max:200'],

            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],

            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'credit_limit' => ['nullable', 'numeric', 'between:0,999999999999.99'],

            'notes' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['boolean'],

            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_active']);

        $this->nullifyBlanks([
            'legal_name', 'code', 'client_type', 'industry', 'registration_no',
            'tax_id', 'email', 'phone', 'website', 'address', 'city', 'state', 'country',
            'payment_terms_days', 'credit_limit', 'notes', 'org_unit_id',
        ]);
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another client already uses that code.',
        ];
    }

    /** @return array<string,mixed> */
    public function clientAttributes(): array
    {
        return $this->validated();
    }
}
