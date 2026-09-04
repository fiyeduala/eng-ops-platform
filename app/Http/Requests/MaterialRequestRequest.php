<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MaterialRequest;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Ask the store for something it already holds.
 *
 * EVERY LINE NAMES A CATALOGUE ITEM, unlike a purchase request, and the rule is `existsInTenant`
 * rather than a bare integer: an id from another organization does not exist as far as this
 * validator is concerned, and the message is the same one an id that never existed gets, so the
 * field cannot be used to find out what exists elsewhere (§75, §84). MaterialRequestService checks
 * it a second time, because a seeder and an import reach the service without passing through here.
 *
 * `warehouse_id` IS OPTIONAL AT THIS LAYER AND REQUIRED AT SUBMISSION. A half-written request with
 * no store on it is a normal draft; a request handed to an approver with no store on it is a
 * request nobody can pick. The service enforces the second, which is where the difference between
 * "still writing" and "your turn" actually lives.
 *
 * NO APPROVED QUANTITIES HERE. `quantity_approved` is written by the approval, against its own
 * permission — a requester who could post it would be approving their own request through the
 * edit form.
 */
class MaterialRequestRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'title' => ['required', 'string', 'max:300'],
            'purpose' => ['nullable', 'string', 'max:20000'],

            'warehouse_id' => ['nullable', 'integer', $this->existsInTenant('warehouses')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],

            'priority' => ['nullable', Rule::in($lists->keys('material_request.priorities', MaterialRequest::DEFAULT_PRIORITIES))],
            'required_by' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.stock_item_id' => ['nullable', 'integer', $this->existsInTenant('stock_items')],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity_requested' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.uom' => ['nullable', 'string', 'max:40'],
            'items.*.required_by' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A line needs both an item and a quantity, or neither.
     *
     * Half-filled lines are how a request for four hundred metres of nothing gets submitted. The
     * empty ones are dropped by ManagesLineItems before they ever reach the table; these two
     * messages are for the ones where somebody meant to finish and did not.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                $named = filled($row['stock_item_id'] ?? null);
                $counted = filled($row['quantity_requested'] ?? null);

                if ($named && ! $counted) {
                    $validator->errors()->add('items.'.$index.'.quantity_requested', 'Say how much is needed.');
                }

                if ($counted && ! $named) {
                    $validator->errors()->add('items.'.$index.'.stock_item_id', 'Choose the item from the catalogue.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'purpose', 'warehouse_id', 'project_id', 'org_unit_id', 'location_id',
            'priority', 'required_by', 'notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'items.*.quantity_requested.gt' => 'A line has to ask for more than nothing.',
            'items.*.stock_item_id.exists' => 'That item could not be found in the stock catalogue.',
        ];
    }

    /** @return array<string,mixed> */
    public function materialRequestAttributes(): array
    {
        return $this->validated();
    }
}
