<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * One movement in or out of one store: a receipt, an issue or a return.
 *
 * THE QUANTITY IS ALWAYS POSITIVE HERE and the sign is decided by the method that posts it. See
 * StockService::issue(), which negates whatever it is given: the form cannot post an issue that
 * adds stock by sending a negative, and the ledger's signs are therefore a property of the verb
 * rather than of the payload (§84).
 *
 * `unit_cost` IS ACCEPTED AND USUALLY IGNORED. It is the one figure that changes what stock on the
 * shelf is worth, and it only means anything on a movement that adds stock — see
 * StockService::post(), where an outbound movement takes the running average regardless of what
 * arrived here. Left as a field on the form because an opening balance genuinely has to say what
 * the stock cost; a receipt from a purchase order never comes through this form at all, it comes
 * from the order price.
 *
 * THERE IS NO `type`. The route says what kind of movement this is. A posted type would let one
 * permission — say, the ability to return stock — reach every other verb in the ledger.
 */
class StockMovementRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', $this->existsInTenant('warehouses')],
            'stock_item_id' => ['required', 'integer', $this->existsInTenant('stock_items')],

            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'unit_cost' => ['nullable', 'numeric', 'between:0,99999999999999'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'batch_ref' => ['nullable', 'string', 'max:120'],
            'expires_on' => ['nullable', 'date'],

            // Not optional, and the message says why. Six months later the only thing anybody can
            // read is this sentence.
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'unit_cost', 'project_id', 'org_unit_id', 'batch_ref', 'expires_on', 'notes', 'occurred_at',
        ]);
    }

    public function messages(): array
    {
        return [
            'quantity.gt' => 'A movement of nothing is not a movement.',
            'reason.required' => 'Say what this movement is for — it is the only explanation the ledger will carry.',
            'occurred_at.before_or_equal' => 'A movement cannot have happened in the future.',
        ];
    }

    /** @return array<string,mixed> the attributes StockService::post() understands */
    public function movementAttributes(): array
    {
        return array_filter([
            'unit_cost' => $this->validated('unit_cost'),
            'project_id' => $this->validated('project_id'),
            'org_unit_id' => $this->validated('org_unit_id'),
            'batch_ref' => $this->validated('batch_ref'),
            'expires_on' => $this->validated('expires_on'),
            'reason' => $this->validated('reason'),
            'notes' => $this->validated('notes'),
            'occurred_at' => $this->validated('occurred_at'),
        ], fn ($value) => $value !== null);
    }
}
