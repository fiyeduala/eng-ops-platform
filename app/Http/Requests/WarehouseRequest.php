<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Warehouse;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Open or amend a store.
 *
 * THE CODE IS UNIQUE PER TENANT AND CHECKED HERE AS WELL AS BY THE INDEX. The index is the
 * guarantee; this rule is what turns a duplicate into a sentence somebody can act on rather than an
 * SQL error page. It is compared after the service upper-cases it — see the `prepareForValidation`
 * below, which does the same normalisation, because "main" passing validation and then colliding
 * with "MAIN" on insert would be a validator that lies.
 *
 * `allow_negative_stock` IS A CHECKBOX WITH CONSEQUENCES and is normalised rather than merely
 * validated: an unticked box is absent from a POST body, and a store that quietly kept its old
 * permission to go negative because somebody unticked it is exactly the failure this platform's
 * boolean handling exists to prevent.
 *
 * `status_id` IS NOT HERE. A store is opened, suspended and closed through WarehouseService, which
 * refuses to close one that still holds stock. A form that could post a status would walk straight
 * past that check (§84).
 */
class WarehouseRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);
        $warehouse = $this->route('warehouse');

        return [
            'code' => [
                'required', 'string', 'max:40',
                $this->uniqueInTenant('warehouses', 'code', $warehouse?->getKey()),
            ],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['nullable', Rule::in($lists->keys('warehouse.types', Warehouse::DEFAULT_TYPES))],

            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'keeper_id' => ['nullable', 'integer', $this->existsInTenant('users')],

            'allow_negative_stock' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['type', 'location_id', 'org_unit_id', 'project_id', 'keeper_id', 'notes']);
        $this->normaliseBooleans(['allow_negative_stock']);

        if (is_string($this->input('code'))) {
            $this->merge([
                'code' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $this->input('code')) ?? '')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another store already uses that code.',
        ];
    }

    /** @return array<string,mixed> */
    public function warehouseAttributes(): array
    {
        return $this->validated();
    }
}
