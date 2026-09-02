<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Ask the market what it would cost.
 *
 * THE INVITATION LIST IS NOT ON THIS FORM. Suppliers are added one at a time through
 * RfqService::invite(), which refuses a blacklisted or suspended company with the reason, and
 * removed through `uninvite()`, which refuses once they have quoted. A multi-select posted with the
 * header would have to reimplement both rules and would silently drop a supplier whose quotation
 * had already arrived.
 *
 * `closes_at` IS A COMMITMENT, NOT A SWITCH. Nothing in this platform closes an enquiry because a
 * timestamp passed — a buyer does, and the reopening is recorded. The date is validated as being in
 * the future on a NEW enquiry only, because an enquiry being tidied up after the fact is a real
 * thing and refusing to save it helps nobody.
 *
 * NOT ACCEPTED: `number`, `status_id`, `issued_by`, `issued_at`, `awarded_quotation_id`,
 * `awarded_by`, `awarded_at`, `award_reason`. The award is one act on every quotation at once —
 * RfqService::award() — and cannot be assembled a field at a time (§84).
 */
class RfqRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'scope' => ['nullable', 'string', 'max:20000'],

            // Kept when the enquiry came from a requisition, so the chain reads end to end. The
            // service copies the LINES across at creation; this is only the pointer.
            'purchase_request_id' => ['nullable', 'integer', $this->existsInTenant('purchase_requests')],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'closes_at' => ['nullable', 'date', Rule::when($this->isMethod('POST'), ['after:now'])],

            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.purchase_request_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.specification' => ['nullable', 'string', 'max:5000'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.uom' => ['nullable', 'string', 'max:40'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                if (filled($row['description'] ?? null) && ! filled($row['quantity'] ?? null)) {
                    $validator->errors()->add('items.'.$index.'.quantity', 'Say how many to quote for.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'scope', 'purchase_request_id', 'project_id', 'org_unit_id',
            'closes_at', 'terms', 'notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'closes_at.after' => 'A closing date in the past gives the suppliers no time to quote.',
        ];
    }

    /** @return array<string,mixed> */
    public function rfqAttributes(): array
    {
        return $this->validated();
    }
}
