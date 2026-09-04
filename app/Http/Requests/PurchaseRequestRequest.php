<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PurchaseRequest;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Ask for something to be bought.
 *
 * THE FORM THAT HAS TO BE EASY. A requisition is filled in by a site engineer with muddy hands on a
 * phone, not by a buyer at a desk, and every field that is not genuinely needed to price the request
 * is optional here. What is required is the shape of the thing: a title, and at least a description
 * and a quantity per line. Everything else — the specification, the estimate, the date — can be
 * added by whoever picks it up.
 *
 * NO TOTALS ARE ACCEPTED. `estimated_total` on both the header and the lines is computed by
 * PurchaseRequestService from quantity × rate. A posted total is a number that disagrees with the
 * lines underneath it the moment anybody edits one, and the approver reads the total.
 *
 * NOR IS `status_id`, `submitted_by`, `approved_by` OR ANY OTHER OUTCOME COLUMN. Submitting and
 * approving are acts with permissions behind them, not fields (§84).
 *
 * THE PRIORITY LIST IS THE COMPANY'S. `Rule::in()` is built from the configured words, so a posted
 * value outside them is refused server-side rather than merely absent from the dropdown (§6).
 */
class PurchaseRequestRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'title' => ['required', 'string', 'max:300'],
            'justification' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],

            'priority' => ['nullable', Rule::in($lists->keys('purchase_request.priorities', PurchaseRequest::DEFAULT_PRIORITIES))],
            'required_by' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            // The lines. An `id` is accepted so an edit can keep a line rather than replacing it,
            // and it is checked against THIS request's own lines by ManagesLineItems — see that
            // trait for why an unrecognised id becomes a new line instead of an error.
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],

            /*
             * THE OTHER HALF OF THE CHAIN (§90), and the reason a delivery can ever become stock.
             *
             * A requisition line is free text first and a catalogue reference second, in that
             * order and never the other way round: "one off machined bush, drawing 4471-B" is a
             * perfectly good thing to ask for and will never be in a catalogue. Where the line
             * DOES name a catalogue item, that item travels through the enquiry, the order and the
             * delivery, and acceptance knows which balance to move. See
             * GoodsReceiptItem::stockItem() for the chain it makes.
             */
            'items.*.stock_item_id' => ['nullable', 'integer', $this->existsInTenant('stock_items')],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.specification' => ['nullable', 'string', 'max:5000'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.uom' => ['nullable', 'string', 'max:40'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'between:0,99999999999999'],
            'items.*.required_by' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A line needs a quantity if it has a description, and a description if it has a quantity.
     *
     * Stated as a pair rather than as two `required_with` rules because the message matters: a
     * requisition line reading "6" with nothing after it is the commonest way a request arrives
     * that nobody can quote against.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                $described = filled($row['description'] ?? null);
                $counted = filled($row['quantity'] ?? null);

                if ($described && ! $counted) {
                    $validator->errors()->add('items.'.$index.'.quantity', 'Say how many are needed.');
                }

                if ($counted && ! $described) {
                    $validator->errors()->add('items.'.$index.'.description', 'Say what is needed.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'justification', 'project_id', 'org_unit_id', 'location_id',
            'priority', 'required_by', 'currency', 'notes',
        ]);

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }

    public function messages(): array
    {
        return [
            'items.*.quantity.gt' => 'A line has to ask for more than nothing.',
            'currency.size' => 'Use the three-letter currency code — NGN, USD, GBP.',
        ];
    }

    /** @return array<string,mixed> */
    public function purchaseRequestAttributes(): array
    {
        return $this->validated();
    }
}
