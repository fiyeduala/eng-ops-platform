<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OrgUnit;
use App\Support\Enums\OrgUnitType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit a node of the organization tree (§3, §73).
 *
 * The structural invariants — no cycles, no department inside a team — are enforced by
 * OrgUnitService, not here, because they must hold for every writer including seeders, imports
 * and console commands. What this class adds is the self-parent check, which deserves a field
 * error next to the select rather than an exception page.
 */
class OrgUnitRequest extends TenantRequest
{
    public function rules(): array
    {
        $unit = $this->unit();

        return [
            'name' => ['required', 'string', 'max:150'],

            'type' => ['required', Rule::enum(OrgUnitType::class)],

            'code' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/',
                $this->uniqueInTenant('org_units', 'code', $unit?->id),
            ],

            'description' => ['nullable', 'string', 'max:2000'],

            // Null is a legitimate value: it means "top of the tree".
            'parent_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // The functional head. An employee id, not a user id — the two are different keys and
            // conflating them is how a unit ends up headed by whoever shares the number.
            'head_employee_id' => ['nullable', 'integer', $this->existsInTenant('employees')],

            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $unit = $this->unit();

            if ($unit === null) {
                return;
            }

            $parentId = $this->input('parent_id');

            if ($parentId !== null && (int) $parentId === (int) $unit->id) {
                $validator->errors()->add('parent_id', 'A unit cannot report to itself.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['parent_id', 'head_employee_id', 'code', 'description', 'sort']);
        $this->normaliseBooleans(['is_active']);
    }

    public function attributes(): array
    {
        return [
            'parent_id' => 'parent unit',
            'head_employee_id' => 'unit head',
            'sort' => 'display order',
        ];
    }

    /**
     * The record being edited, or null on create. Read from the bound route model, never from a
     * hidden id field.
     */
    private function unit(): ?OrgUnit
    {
        $bound = $this->route('org_unit');

        return $bound instanceof OrgUnit ? $bound : null;
    }
}
