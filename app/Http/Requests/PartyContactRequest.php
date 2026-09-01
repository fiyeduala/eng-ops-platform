<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A named person at a vendor or a client.
 *
 * ONE REQUEST FOR BOTH, unlike the policies, and the difference is worth stating because it looks
 * inconsistent. VendorContactPolicy and ClientContactPolicy are separate because they ask their
 * PARENTS different questions and the two registers will keep diverging. The shape of a contact —
 * a name, a job title, a phone number, an email — will not diverge, because it is the shape of a
 * person. Two identical validation classes would be two places to forget the same change.
 *
 * THE PARENT IS NOT IN THE PAYLOAD. `vendor_id` / `client_id` come from the bound route model, which
 * has already been through the tenant scope and the parent's policy, so a posted foreign key cannot
 * attach a contact to a company the actor cannot open (§84). ManagesPartyContacts strips both keys
 * again on the way in, belt and braces.
 *
 * `is_primary` IS A CLAIM, NOT A GUARANTEE. Ticking it demotes whoever held it before, inside the
 * same transaction — see ManagesPartyContacts::demoteOtherPrimaries(). Two primary contacts is the
 * state that makes a register useless for "who do we call", so the data model refuses to hold it.
 */
class PartyContactRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'phone' => ['nullable', 'string', 'max:60'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_primary', 'is_active']);
        $this->nullifyBlanks(['position', 'email', 'phone', 'notes']);
    }

    /** @return array<string,mixed> */
    public function contactAttributes(): array
    {
        return $this->validated();
    }
}
