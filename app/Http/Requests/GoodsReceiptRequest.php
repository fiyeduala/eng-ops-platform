<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GoodsReceipt;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Count what arrived.
 *
 * TWO QUANTITIES PER LINE, NEVER ONE. `quantity_accepted` and `quantity_rejected` are separate
 * fields because "eight arrived and two were damaged" is a different fact from "six arrived", and
 * only the first can be put to the supplier. A single signed figure would collapse them and lose
 * the half that the claim is made out of.
 *
 * A REJECTION NEEDS A REASON, checked here so the storeman gets an inline message and checked again
 * in GoodsReceiptService so a seeder or an import cannot skip it. A refused quantity with no reason
 * is a number the buyer cannot chase and the supplier cannot answer.
 *
 * THE ORDER LINE IS POSTED AS AN ID and is proved to belong to THIS receipt's order inside the
 * service — not here. Validation can say "that is not an integer"; only a scoped lookup through the
 * order can say "that line is not on this order" (§84, §94).
 *
 * NOT ACCEPTED: `number`, `status_id`, `received_by`, `received_at`, `inspected_by`,
 * `inspected_at`. Confirming a delivery and inspecting it are acts with permissions behind them.
 */
class GoodsReceiptRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);
        $conditions = $lists->keys('goods_receipt.conditions', GoodsReceipt::DEFAULT_CONDITIONS);

        return [
            // Re-resolved in the service, which also refuses an order that is not open for
            // deliveries and applies the buyer-is-not-the-receiver control.
            'purchase_order_id' => ['required', 'integer', $this->existsInTenant('purchase_orders')],

            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],

            'delivery_note_ref' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:200'],
            'vehicle_ref' => ['nullable', 'string', 'max:80'],

            'inspection_notes' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.quantity_accepted' => ['nullable', 'numeric', 'between:0,99999999999999'],
            'items.*.quantity_rejected' => ['nullable', 'numeric', 'between:0,99999999999999'],
            'items.*.rejection_reason' => ['nullable', 'string', 'max:2000'],
            'items.*.condition' => ['nullable', Rule::in($conditions)],
            'items.*.batch_ref' => ['nullable', 'string', 'max:80'],
            'items.*.expires_on' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Say why anything was refused.
     *
     * Written as an `after` rule rather than `required_with` because the trigger is a quantity
     * GREATER THAN ZERO, not a quantity being present — the form posts a zero on every line the
     * receiver did not touch, and `required_with` would demand a reason for all of them.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                $rejected = (float) ($row['quantity_rejected'] ?? 0);

                if ($rejected > 0 && blank($row['rejection_reason'] ?? null)) {
                    $validator->errors()->add(
                        'items.'.$index.'.rejection_reason',
                        'Say what was wrong with the quantity you are refusing.'
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'location_id', 'delivery_note_ref', 'carrier', 'vehicle_ref',
            'inspection_notes', 'notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'purchase_order_id.required' => 'A delivery is recorded against an order. Choose the order.',
        ];
    }

    /** @return array<string,mixed> */
    public function goodsReceiptAttributes(): array
    {
        return $this->validated();
    }
}
