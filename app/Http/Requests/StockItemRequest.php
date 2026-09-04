<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StockItem;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Add or amend a catalogue entry.
 *
 * THE UNIT OF MEASURE IS FREE TEXT AND STAYS THAT WAY. See StockItemService::normaliseUom(): a
 * company that measures cement in bags and cable in metres and gas in kilogrammes should not have
 * to have any of those three added to a list by an administrator before the storekeeper can enter
 * an item. The value is displayed beside quantities and carried onto documents; nothing compares it
 * to anything, which is exactly what makes a free unit safe.
 *
 * `last_purchase_price` IS NOT HERE. It is written by the goods-receipt chain from what an order
 * actually charged — see StockItemService::recordPurchasePrice() — and a field somebody can type is
 * a field that says what its author wishes had been paid.
 *
 * NOR IS `status_id`. Bringing an item into use and discontinuing it are acts with permissions
 * behind them (§84).
 */
class StockItemRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);
        $item = $this->route('stock_item');

        return [
            'code' => [
                'required', 'string', 'max:60',
                $this->uniqueInTenant('stock_items', 'code', $item?->getKey()),
            ],
            'name' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'specification' => ['nullable', 'string', 'max:20000'],

            'category' => ['nullable', Rule::in($lists->keys('stock_item.categories', StockItem::DEFAULT_CATEGORIES))],
            'uom' => ['required', 'string', 'max:40'],

            'manufacturer' => ['nullable', 'string', 'max:200'],
            'part_number' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:120'],

            'is_stock_item' => ['boolean'],
            'is_hazardous' => ['boolean'],

            // Both optional, and both meaningless without the other — see `withValidator()`.
            'reorder_level' => ['nullable', 'numeric', 'between:0,99999999999999'],
            'reorder_quantity' => ['nullable', 'numeric', 'between:0,99999999999999'],

            'storage_conditions' => ['nullable', 'string', 'max:2000'],
            'shelf_life_days' => ['nullable', 'integer', 'between:1,36500'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    /**
     * A reorder level with no reorder quantity is a warning nobody can act on.
     *
     * The pair is what makes the low-stock notice useful: "you are below 50 metres" is an alarm,
     * and "you are below 50 metres, the usual order is 200" is an instruction. The reverse — a
     * quantity with no level — never fires at all, which is worse, because it looks configured.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $level = $this->input('reorder_level');
            $quantity = $this->input('reorder_quantity');

            if (filled($level) && blank($quantity)) {
                $validator->errors()->add(
                    'reorder_quantity',
                    'Say how much to order when it drops this low.'
                );
            }

            if (filled($quantity) && blank($level)) {
                $validator->errors()->add(
                    'reorder_level',
                    'Say what level should trigger the reorder.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'specification', 'category', 'manufacturer', 'part_number', 'barcode',
            'reorder_level', 'reorder_quantity', 'storage_conditions', 'shelf_life_days', 'notes',
        ]);

        $this->normaliseBooleans(['is_stock_item', 'is_hazardous']);

        if (is_string($this->input('code'))) {
            $this->merge([
                'code' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $this->input('code')) ?? '')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another catalogue entry already uses that code.',
            'uom.required' => 'Say what this is counted in — metres, each, kg, bags.',
        ];
    }

    /** @return array<string,mixed> */
    public function stockItemAttributes(): array
    {
        return $this->validated();
    }
}
