<?php

declare(strict_types=1);

namespace App\Http\Requests;

class LeaveTypeRequest extends TenantRequest
{
    public function rules(): array
    {
        $type = $this->route('leave_type');

        return [
            'code' => ['required', 'string', 'max:20', $this->uniqueInTenant('leave_types', 'code', $type?->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'days_per_year' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'carry_over_limit' => ['required', 'numeric', 'min:0', 'max:366'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'min_notice_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_paid' => ['boolean'],
            'requires_approval' => ['boolean'],
            'allow_half_days' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['description', 'days_per_year', 'max_consecutive_days', 'sort']);
        $this->normaliseBooleans(['is_paid', 'requires_approval', 'allow_half_days', 'is_active']);
        $this->merge([
            // ConvertEmptyStringsToNull has already turned a blank box into null.
            'carry_over_limit' => in_array($this->input('carry_over_limit'), [null, ''], true) ? 0 : $this->input('carry_over_limit'),
            'min_notice_days' => in_array($this->input('min_notice_days'), [null, ''], true) ? 0 : $this->input('min_notice_days'),
        ]);
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }
}
