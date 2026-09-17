<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

class BudgetRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'fiscal_year' => ['required', 'integer', 'between:2000,2200'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'cost_center_id' => ['nullable', 'integer', $this->existsInTenant('cost_centers')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'owner_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'notes' => ['nullable', 'string', 'max:20000'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.category' => ['nullable', 'string', 'max:60'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.allocated_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999999.99'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $lines = array_filter((array) $this->input('items'), fn ($line) => filled($line['description'] ?? null));
            if ($lines === []) {
                $validator->errors()->add('items', 'Add at least one budget line.');
            }
            $categories = array_map(fn ($line) => str((string) ($line['category'] ?? 'other'))->snake()->toString(), $lines);
            if (count($categories) !== count(array_unique($categories))) {
                $validator->errors()->add('items', 'Use each budget category once so actual costs are not counted twice.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['cost_center_id', 'project_id', 'org_unit_id', 'owner_id', 'notes']);
    }

    public function budgetAttributes(): array
    {
        return $this->validated();
    }
}
