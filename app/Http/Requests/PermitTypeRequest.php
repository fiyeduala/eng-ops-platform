<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Define a kind of permit this company issues.
 *
 * THIS IS CONFIGURATION, NOT A RECORD, and it is the clearest example in Phase 4 of why §73 says
 * what it says. A refinery's permit set — hot work, confined space, excavation, working at height,
 * energised electrical, radiography — is not a fabrication shop's and is not a hospital estates
 * department's. Shipped as an enum it would be wrong for almost everybody and changeable by
 * nobody; shipped as a tenant table it is a screen a safety manager edits on a Tuesday afternoon.
 *
 * THE PRECAUTION TEMPLATE IS THE POINT OF THE TABLE. Each type carries the checks that get copied
 * onto every permit raised against it, so that "test the atmosphere before entry" is not something
 * an individual supervisor has to remember to type. They are copied, not referenced: editing a
 * type must never silently change the precautions on a permit somebody has already signed.
 *
 * `code` IS UNIQUE PER ORGANIZATION, never globally — two companies may both call their hot-work
 * permit HW, and a global unique index would leak the other tenant's existence through a
 * validation message.
 */
class PermitTypeRequest extends TenantRequest
{
    public function rules(): array
    {
        $ignore = $this->route('permit_type')?->getKey();

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                $this->uniqueInTenant('permit_types', 'code', $ignore),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'max:20'],

            // Hours, because permits are worked to in hours. A week is 168 and a fortnight is the
            // outer limit anybody should be issuing against a single permit.
            'default_duration_hours' => ['nullable', 'integer', 'between:1,336'],

            'requires_gas_test' => ['boolean'],
            'requires_isolation' => ['boolean'],

            'precautions' => ['nullable', 'array', 'max:100'],
            'precautions.*.description' => ['nullable', 'string', 'max:1000'],
            'precautions.*.is_required' => ['boolean'],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['requires_gas_test', 'requires_isolation', 'is_active']);

        $this->nullifyBlanks(['description', 'color', 'default_duration_hours', 'sort_order']);
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers, hyphens and underscores only — the code appears in permit numbers.',
        ];
    }

    /**
     * The type itself, with the precaution rows folded into the JSON column.
     *
     * Blank rows are dropped rather than rejected: an empty last line is what an "add another"
     * button leaves behind, and failing the submission over it would lose everything above it.
     *
     * @return array<string,mixed>
     */
    public function permitTypeAttributes(): array
    {
        $data = $this->validated();

        $rows = [];

        foreach ($data['precautions'] ?? [] as $row) {
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $rows[] = [
                'description' => $description,
                // The form pairs every checkbox with a hidden `0`, so the key is always present.
                // The `?? true` is the fallback for a row that arrives from anywhere else — a
                // precaution defaulting to required is the safe way to be wrong.
                'is_required' => (bool) ($row['is_required'] ?? true),
            ];
        }

        unset($data['precautions']);

        $data['precaution_template'] = $rows;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
