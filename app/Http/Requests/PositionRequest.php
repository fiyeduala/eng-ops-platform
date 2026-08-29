<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Position;

/**
 * A job title (§3).
 *
 * Worth restating because the two are constantly confused: a position describes the JOB, a role
 * describes SYSTEM ACCESS. Nothing here grants a permission, and `is_management` is a reporting
 * flag — it is never consulted by the access engine.
 */
class PositionRequest extends TenantRequest
{
    public function rules(): array
    {
        $position = $this->position();

        return [
            'title' => ['required', 'string', 'max:150'],

            'code' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/',
                $this->uniqueInTenant('positions', 'code', $position?->id),
            ],

            'description' => ['nullable', 'string', 'max:2000'],

            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // Seniority band. Ordering and reporting only.
            'grade' => ['nullable', 'integer', 'min:1', 'max:99'],

            'is_management' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['org_unit_id', 'code', 'description', 'grade']);
        $this->normaliseBooleans(['is_management', 'is_active']);
    }

    public function attributes(): array
    {
        return [
            'org_unit_id' => 'organization unit',
            'is_management' => 'management position',
        ];
    }

    private function position(): ?Position
    {
        $bound = $this->route('position');

        return $bound instanceof Position ? $bound : null;
    }
}
