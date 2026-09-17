<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

class PettyCashAccountRequest extends TenantRequest
{
    public function rules(): array
    {
        $account = $this->route('petty_cash_account');

        return [
            'code' => ['required', 'string', 'max:40', $this->uniqueInTenant('petty_cash_accounts', 'code', $account?->getKey())],
            'name' => ['required', 'string', 'max:200'],
            'custodian_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'float_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['boolean'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['custodian_id', 'org_unit_id', 'project_id', 'float_amount', 'notes']);
        $this->normaliseBooleans(['is_active']);
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }
}
