<?php

declare(strict_types=1);

namespace App\Http\Requests;

class CostCenterRequest extends TenantRequest
{
    public function rules(): array
    {
        $center = $this->route('cost_center');

        return [
            'code' => ['required', 'string', 'max:40', $this->uniqueInTenant('cost_centers', 'code', $center?->getKey())],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'manager_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['description', 'org_unit_id', 'project_id', 'manager_id']);
        $this->normaliseBooleans(['is_active']);
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    public function costCenterAttributes(): array
    {
        return $this->validated();
    }
}
