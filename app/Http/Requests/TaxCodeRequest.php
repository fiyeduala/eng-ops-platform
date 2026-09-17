<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TaxCode;
use Illuminate\Validation\Rule;

class TaxCodeRequest extends TenantRequest
{
    public function rules(): array
    {
        $code = $this->route('tax_code');

        return [
            'code' => ['required', 'string', 'max:20', $this->uniqueInTenant('tax_codes', 'code', $code?->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'applies_to' => ['required', Rule::in(array_keys(TaxCode::APPLIES_TO))],
            'is_recoverable' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['description']);
        $this->normaliseBooleans(['is_recoverable', 'is_active']);
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }
}
