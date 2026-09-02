<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Enter what a supplier came back with.
 *
 * TYPED IN BY US, ABOUT THEM. There is no supplier portal in V1, so a quotation is a buyer copying
 * figures off a PDF. That shapes the form: `reference` is THEIR number and is what they will quote
 * back on the phone, `document_id` points at the file it was read from, and the totals are
 * recomputed from the lines rather than typed, so a transcription error shows up as a total that
 * does not match the paper.
 *
 * THE TAX IS A FIGURE. `tax_amount` is entered as it appears on their quotation and nothing here
 * knows what it is a percentage of or what it is called — §47, and the reason the platform can be
 * used in a country whose rules nobody wrote into it.
 *
 * QUOTATIONS DEFAULT TO CONFIDENTIAL, which is set on the model rather than here so a seeder and an
 * import get the same answer. What one supplier quoted is the single most damaging thing to leak
 * from a procurement file, and the field is on the form so a buyer can widen it deliberately.
 *
 * NOT ACCEPTED: `number`, `status_id`, `subtotal`, `total`. Acceptance is decided by the AWARD on
 * the enquiry, never on the quotation itself.
 */
class QuotationRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            // Both resolved again inside QuotationService against the tenant-scoped query, and the
            // supplier is checked against the enquiry's invitation list there (§75, §84).
            'rfq_id' => ['nullable', 'integer', $this->existsInTenant('rfqs')],
            'vendor_id' => ['required', 'integer', $this->existsInTenant('vendors')],

            'reference' => ['nullable', 'string', 'max:120'],
            'quoted_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quoted_on'],

            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],

            'tax_amount' => ['nullable', 'numeric', 'between:0,9999999999999999.99'],

            'payment_terms' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:10000'],
            'lead_time_days' => ['nullable', 'integer', 'between:0,3650'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'document_id' => ['nullable', 'integer', $this->existsInTenant('documents')],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.rfq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.uom' => ['nullable', 'string', 'max:40'],
            'items.*.unit_price' => ['nullable', 'numeric', 'between:0,99999999999999'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'between:0,3650'],
            'items.*.is_alternative' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A priced line needs a quantity, and a quantity needs something it is a quantity of.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                if (filled($row['description'] ?? null) && ! filled($row['quantity'] ?? null)) {
                    $validator->errors()->add('items.'.$index.'.quantity', 'Say how many this price is for.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'rfq_id', 'reference', 'quoted_on', 'valid_until', 'currency', 'exchange_rate',
            'payment_terms', 'delivery_terms', 'lead_time_days', 'notes', 'document_id',
        ]);

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Say which supplier this quotation came from.',
            'valid_until.after_or_equal' => 'A quotation cannot expire before it was given.',
            'currency.size' => 'Use the three-letter currency code — NGN, USD, GBP.',
        ];
    }

    /** @return array<string,mixed> */
    public function quotationAttributes(): array
    {
        return $this->validated();
    }
}
