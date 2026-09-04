<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * What was actually on the shelf when somebody looked.
 *
 * THE FIELD IS THE COUNT, NOT THE DIFFERENCE, and that is the entire design of this form. Asking
 * for an adjustment of "-6" asks the storekeeper to do arithmetic against a number the system is
 * already claiming, and the commonest way a stocktake goes wrong is that the arithmetic is done
 * against yesterday's figure. StockService::adjust() takes the count and works out the variance
 * itself, inside the lock, so the variance is computed against what the balance says at the moment
 * of writing rather than at the moment the page was rendered.
 *
 * ZERO IS A LEGITIMATE COUNT — `gte:0`, not `gt:0`. "There is none left" is the most useful thing a
 * stocktake can discover.
 */
class StockCountRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', $this->existsInTenant('warehouses')],
            'stock_item_id' => ['required', 'integer', $this->existsInTenant('stock_items')],

            'counted_quantity' => ['required', 'numeric', 'gte:0', 'max:99999999999999'],

            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['notes']);
    }

    public function messages(): array
    {
        return [
            'counted_quantity.required' => 'Enter what was counted, even if it was none.',
            'reason.required' => 'Say what this count was — a stocktake, a spot check, a correction.',
        ];
    }
}
