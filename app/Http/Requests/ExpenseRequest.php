<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expense;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * An expense claim. Tax rate, totals, currency and status are never on the form — the service
 * derives them — so only the net amount and the chosen tax code arrive here.
 */
class ExpenseRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', Rule::in(app(OptionList::class)->keys('expense.categories', Expense::DEFAULT_CATEGORIES))],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'vendor_id' => ['nullable', 'integer', $this->existsInTenant('vendors')],
            'payee_name' => ['nullable', 'string', 'max:200'],
            'claimant_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'receipt_reference' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'tax_code_id' => ['nullable', 'integer', $this->existsInTenant('tax_codes', activeOnly: true)
                ->where(fn ($q) => $q->whereIn('applies_to', ['both', 'purchases']))],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'cost_center_id' => ['nullable', 'integer', $this->existsInTenant('cost_centers', activeOnly: true)],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'vendor_id', 'payee_name', 'claimant_id', 'receipt_reference',
            'tax_code_id', 'project_id', 'org_unit_id', 'cost_center_id', 'notes',
        ]);
    }
}
