<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ChecklistTemplate;
use Illuminate\Validation\Rule;

/**
 * A joining or leaving list, and its steps.
 *
 * The items arrive as an array and are written wholesale by the controller. That is safe here in a
 * way it would not be on a person's own checklist: a template has no ticks to lose, while rewriting
 * somebody's copied list would destroy the record of who did what.
 */
class ChecklistTemplateRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ChecklistTemplate::KINDS)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],

            'items' => ['nullable', 'array', 'max:100'],
            'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.responsible_role_id' => ['nullable', 'integer', $this->existsInTenant('roles')],
            'items.*.requires_note' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['description', 'org_unit_id']);
    }
}
