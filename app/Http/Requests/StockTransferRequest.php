<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Move stock from one store to another.
 *
 * `different:from_warehouse_id` IS THE ONLY RULE HERE WORTH READING. A transfer to the store it
 * came from writes two rows that cancel out, leaves the balance where it was, and reads in the
 * ledger as a movement that happened — which is a lie somebody will chase. StockService refuses it
 * too; this is the half that says so in a sentence rather than an exception page.
 */
class StockTransferRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer', $this->existsInTenant('warehouses')],
            'to_warehouse_id' => [
                'required', 'integer', 'different:from_warehouse_id',
                $this->existsInTenant('warehouses'),
            ],
            'stock_item_id' => ['required', 'integer', $this->existsInTenant('stock_items')],

            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['project_id', 'notes', 'occurred_at']);
    }

    public function messages(): array
    {
        return [
            'to_warehouse_id.different' => 'A transfer has to go somewhere else.',
            'quantity.gt' => 'A transfer of nothing is not a transfer.',
            'reason.required' => 'Say why the stock is moving.',
        ];
    }

    /** @return array<string,mixed> */
    public function transferAttributes(): array
    {
        return array_filter([
            'project_id' => $this->validated('project_id'),
            'reason' => $this->validated('reason'),
            'notes' => $this->validated('notes'),
            'occurred_at' => $this->validated('occurred_at'),
        ], fn ($value) => $value !== null);
    }
}
