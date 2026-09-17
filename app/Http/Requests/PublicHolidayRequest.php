<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PublicHolidayRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'holiday_date' => ['required', 'date'],
            'is_recurring' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_recurring']);
    }
}
