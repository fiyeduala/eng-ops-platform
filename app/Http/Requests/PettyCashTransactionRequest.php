<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expense;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/** A top-up or a payout. Which one comes from the route, never from the form. */
class PettyCashTransactionRequest extends TenantRequest
{
    public function rules(): array
    {
        $payout = $this->routeIs('petty-cash.disburse');

        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'transacted_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:500'],
            'category' => $payout
                ? ['required', 'string', Rule::in(app(OptionList::class)->keys('expense.categories', Expense::DEFAULT_CATEGORIES))]
                : ['prohibited'],
            'payee_name' => ['nullable', 'string', 'max:200'],
            'receipt_reference' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['category', 'payee_name', 'receipt_reference', 'project_id']);
    }
}
