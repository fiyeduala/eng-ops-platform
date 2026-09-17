<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Income;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

class IncomeRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', Rule::in(app(OptionList::class)->keys('income.categories', Income::DEFAULT_CATEGORIES))],
            'income_date' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:income_date'],
            'client_id' => ['nullable', 'integer', $this->existsInTenant('clients')],
            'contract_id' => ['nullable', 'integer', $this->existsInTenant('contracts')],
            'invoice_reference' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'tax_code_id' => ['nullable', 'integer', $this->existsInTenant('tax_codes', activeOnly: true)
                ->where(fn ($q) => $q->whereIn('applies_to', ['both', 'sales']))],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'due_on', 'client_id', 'contract_id', 'invoice_reference',
            'tax_code_id', 'project_id', 'org_unit_id', 'notes',
        ]);
    }
}
