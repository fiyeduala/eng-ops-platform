<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Vendor;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Add a supplier to the register, or correct one already on it.
 *
 * WHAT THIS FORM DELIBERATELY CANNOT TOUCH is longer than usual, and every omission is the same
 * argument: a field that decides whether money leaves the company must not be reachable through
 * the screen everybody uses to fix a phone number.
 *
 *   · The FIVE BANK COLUMNS are not here. They have their own request, their own permission
 *     (`vendor.bank`) and their own endpoint — see VendorBankDetailsRequest, which explains why.
 *     Invoice-redirection fraud works precisely because changing an account number looks like
 *     ordinary maintenance; here it cannot be done by accident or in passing.
 *   · `is_blacklisted` and its three companions are not here. Blacklisting demands a reason and
 *     goes through VendorService::blacklist(), so the register can always answer WHY somebody was
 *     barred — a boolean on an edit form can be flipped back by the person who was caught.
 *   · `rating` and `rated_count` are not here. A rating is the average of recorded evaluations,
 *     not a number a buyer types about their friend.
 *   · `status_id` is not here. Prequalification position moves through Engine 2 (§84).
 *
 * `prequalified_on` AND ITS EXPIRY ARE ACCEPTED, because recording that an assessment happened is
 * clerical; deciding the vendor is approved is not, and that is the status transition, gated on
 * `vendor.manage`. The two are kept apart on purpose: a coordinator files the certificate, a
 * manager accepts it.
 *
 * `code` IS UNIQUE PER ORGANIZATION AND OPTIONAL. Companies that already run an ERP paste their
 * existing supplier code in so the two systems can be reconciled; companies that do not, leave it
 * blank and use the generated number.
 *
 * NOT ACCEPTED: `number`, `status_id`, bank columns, blacklist columns, `rating`, `rated_count`,
 * `organization_id`, `created_by`.
 */
class VendorRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],

            // Ignore this vendor's own row when editing, or saving an unchanged form fails.
            'code' => ['nullable', 'string', 'max:50', $this->uniqueInTenant('vendors', 'code', $this->route('vendor')?->id)],

            'vendor_type' => ['nullable', Rule::in($lists->keys('vendor.types', Vendor::DEFAULT_TYPES))],
            'category' => ['nullable', Rule::in($lists->keys('vendor.categories', Vendor::DEFAULT_CATEGORIES))],

            'registration_no' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],

            'email' => ['nullable', 'email:rfc', 'max:200'],
            'phone' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'url', 'max:200'],

            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],

            // Days, not a date. "30 days net" is the term; the date is arithmetic done per invoice.
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'credit_limit' => ['nullable', 'numeric', 'between:0,999999999999.99'],

            'prequalified_on' => ['nullable', 'date'],
            'prequalification_expires_on' => ['nullable', 'date', 'after:prequalified_on'],

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
            'legal_name', 'trade_name', 'code', 'vendor_type', 'category', 'registration_no',
            'tax_id', 'email', 'phone', 'website', 'address', 'city', 'state', 'country',
            'payment_terms_days', 'credit_limit', 'prequalified_on',
            'prequalification_expires_on', 'notes', 'org_unit_id',
        ]);
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another vendor already uses that code. Codes have to be unique so purchase orders can be reconciled against them.',
            'prequalification_expires_on.after' => 'A prequalification cannot expire before it was granted.',
        ];
    }

    /** @return array<string,mixed> */
    public function vendorAttributes(): array
    {
        return $this->validated();
    }
}
