<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Commit the company's money.
 *
 * THE THREE POINTERS ARE THE CHAIN. `quotation_id`, `purchase_request_id` and `contract_id` are
 * what make an order traceable back to why it exists, and each is validated twice: as a row of this
 * tenant here, and as belonging to THIS SUPPLIER in PurchaseOrderService. The second check is the
 * one that matters — a call-off against a framework agreement let with a different company is how a
 * contract quietly acquires spend that was never let under it.
 *
 * NO TOTALS ARE ACCEPTED. `subtotal` and `total` are computed from the lines plus the tax figure.
 * An order whose header total disagrees with its own lines is the document a supplier invoices
 * against.
 *
 * THE FORM IS ONLY USABLE WHILE THE ORDER IS A DRAFT. PurchaseOrderPolicy refuses `update` on an
 * issued order to everybody, and the service refuses it again on the way in. Amending an issued
 * order means cancelling it and raising another — which is what the paperwork does too.
 *
 * NOT ACCEPTED: `number`, `status_id`, and every outcome column — submitted, approved, rejected,
 * issued, cancelled, with their people and their timestamps.
 */
class PurchaseOrderRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:300'],

            'vendor_id' => ['required', 'integer', $this->existsInTenant('vendors')],
            'quotation_id' => ['nullable', 'integer', $this->existsInTenant('quotations')],
            'purchase_request_id' => ['nullable', 'integer', $this->existsInTenant('purchase_requests')],
            'contract_id' => ['nullable', 'integer', $this->existsInTenant('contracts')],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],
            'delivery_address' => ['nullable', 'string', 'max:2000'],

            'ordered_on' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date'],

            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'tax_amount' => ['nullable', 'numeric', 'between:0,9999999999999999.99'],

            // Free text, prefilled from the company's default. Payment terms are a sentence in a
            // contract, not a code, and every attempt to enumerate them ends in an "other" box.
            'payment_terms' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.quotation_item_id' => ['nullable', 'integer'],
            'items.*.purchase_request_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.specification' => ['nullable', 'string', 'max:5000'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.uom' => ['nullable', 'string', 'max:40'],
            'items.*.unit_price' => ['nullable', 'numeric', 'between:0,99999999999999'],
            'items.*.required_by' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                if (filled($row['description'] ?? null) && ! filled($row['quantity'] ?? null)) {
                    $validator->errors()->add('items.'.$index.'.quantity', 'Say how many are being ordered.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'title', 'quotation_id', 'purchase_request_id', 'contract_id',
            'project_id', 'org_unit_id', 'location_id', 'delivery_address',
            'ordered_on', 'required_by', 'currency', 'exchange_rate',
            'payment_terms', 'delivery_terms', 'notes',
        ]);

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'An order has to name the supplier it is placed with.',
            'currency.size' => 'Use the three-letter currency code — NGN, USD, GBP.',
        ];
    }

    /** @return array<string,mixed> */
    public function purchaseOrderAttributes(): array
    {
        return $this->validated();
    }
}
