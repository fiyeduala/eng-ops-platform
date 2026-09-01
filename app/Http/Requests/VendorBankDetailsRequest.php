<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Change where this supplier's money goes.
 *
 * A SEPARATE REQUEST, A SEPARATE FORM AND A SEPARATE PERMISSION, for one reason: invoice-redirection
 * fraud is the most common way an engineering company loses six figures in an afternoon, and it
 * almost never involves breaking in. It involves an email that looks like it came from a supplier,
 * a helpful person who updates a field, and a payment run three days later. Every control here is
 * aimed at that sequence rather than at an attacker.
 *
 *   · Bank columns are absent from VendorRequest and listed in VendorService::protectedAttributes(),
 *     so they cannot ride along on an ordinary vendor save even if the keys are posted by hand (§84).
 *   · `vendor.bank` is marked sensitive in PermissionCatalogue and covers reading as well as
 *     writing, because a stored account number is worth stealing on its own.
 *   · VendorService::updateBankDetails() records an activity entry naming the FIELDS THAT CHANGED
 *     and never their values, while Auditable captures the before/after separately in the audit
 *     trail — so the timeline is safe to show anyone who can see the vendor, and the forensic
 *     record still exists for whoever is entitled to it (§59, §68).
 *
 * ALL FIVE FIELDS ARE NULLABLE, INCLUDING THE ACCOUNT NUMBER. Clearing bank details is a legitimate
 * and useful act — it is what you do the moment you suspect the ones you hold are wrong, and
 * refusing to allow it would push somebody towards typing in a number they are unsure of.
 *
 * NO FORMAT VALIDATION BEYOND LENGTH. §47's reasoning applies to banking as much as to tax: an IBAN,
 * a US routing-plus-account, a Nigerian NUBAN and an Indian IFSC have nothing structural in common,
 * and a regex that assumes one of them silently locks out every company that uses another.
 */
class VendorBankDetailsRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account_name' => ['nullable', 'string', 'max:200'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_branch' => ['nullable', 'string', 'max:150'],
            'bank_swift' => ['nullable', 'string', 'max:32'],

            // Not stored. Written into the activity narrative so the register can answer "why did
            // this change" without holding the answer in a field somebody can quietly edit later.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
        ]);
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the bank details are changing, and how it was confirmed. A change nobody can account for is the one that turns out to have come from a forged email.',
        ];
    }

    /**
     * Only the five columns. `reason` is handled separately by the controller.
     *
     * @return array<string,mixed>
     */
    public function bankAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
        ]));
    }
}
