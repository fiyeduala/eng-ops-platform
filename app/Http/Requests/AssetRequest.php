<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Asset;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Register a thing the company owns, or correct its particulars.
 *
 * THE CODE IS TYPED, NOT GENERATED, and that is the rule NumberGenerator states: generated numbers
 * are for records that are EVENTS, typed codes are for things that EXIST. An asset code is stencilled
 * on the casing, written on the insurance schedule and read off a sticker by a fitter who has never
 * opened this system. It has to be the word already painted on the machine.
 *
 * NONE OF THE FOUR CUSTODY COLUMNS ARE HERE. Not `custodian_id`, not `org_unit_id`, not `project_id`,
 * not `location_id`. Custody moves through AssetService::transferCustody(), which writes the handover
 * row and the cache in one transaction; a form that could post any of the four would move a generator
 * across the yard leaving no trail, which is the exact failure the whole module exists to prevent.
 * They are on the service's deny list too, so this omission is a courtesy rather than the control
 * (§84).
 *
 * NEITHER IS `is_vehicle`, `status_id`, `disposed_on` OR `goods_receipt_item_id`. A vehicle row is
 * created by FleetService; a status moves along the configured graph; a disposal date is stamped by
 * `retire()` behind `asset.dispose`; and the receipt link is written by the procurement chain. Each
 * of those is a decision with its own permission, and a text input beside the manufacturer's name is
 * not where any of them belongs.
 *
 * THE SERIAL NUMBER IS NOT UNIQUE, deliberately. Manufacturers reuse them across product lines, a
 * second-hand purchase can arrive with the plate missing, and an import from a spreadsheet will have
 * a hundred rows reading "N/A". The `code` is what the company guarantees; the serial is what the
 * maker claims.
 */
class AssetRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);
        $asset = $this->route('asset');

        return [
            'code' => [
                'required', 'string', 'max:60',
                $this->uniqueInTenant('assets', 'code', $asset?->getKey()),
            ],
            'name' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'category' => ['nullable', Rule::in($lists->keys('asset.categories', Asset::DEFAULT_CATEGORIES))],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:200'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'tag_number' => ['nullable', 'string', 'max:120'],

            'acquired_on' => ['nullable', 'date'],
            // A cost is never negative and a machine that cost nothing is a donation somebody should
            // record as such. `min:0` rather than `gt:0` because zero is how a gifted asset is
            // honestly recorded, and a negative purchase price is only ever a typing slip.
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'vendor_id' => ['nullable', 'integer', $this->existsInTenant('vendors')],

            // Not `after:acquired_on`: warranties are commonly back-dated to a commissioning date
            // that precedes delivery, and a rule that refuses that would send people to the notes
            // field instead — which is worse, because nothing can query the notes field.
            'warranty_expires_on' => ['nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:20000'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'category', 'manufacturer', 'model', 'serial_number', 'tag_number',
            'acquired_on', 'purchase_cost', 'vendor_id', 'warranty_expires_on', 'notes',
        ]);

        if (is_string($this->input('code'))) {
            $this->merge([
                'code' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $this->input('code')) ?? '')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another asset already carries that code.',
            'code.required' => 'Give the asset the code that is written on it.',
        ];
    }

    /** @return array<string,mixed> */
    public function assetAttributes(): array
    {
        return $this->validated();
    }
}
