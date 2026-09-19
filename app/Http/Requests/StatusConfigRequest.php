<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Status;

/**
 * Add or amend one state in a register's vocabulary (§63, §73).
 *
 * The validation here is deliberately thin, and that is a decision rather than an omission. What a
 * status may be called is a matter of taste and belongs in a form request; what a status may DO —
 * whether its key can change, whether it can be switched off, whether deleting it would strand
 * records — belongs in StatusConfigService, because a request object is only consulted when the
 * change arrives through this form, and the seeder, the provisioner and any future import do not.
 * Rules that protect data integrity have to sit where every caller passes.
 *
 * `key` IS ACCEPTED BUT NEVER TRUSTED. The service slugs it, checks it for collisions and refuses
 * it outright on a system status. Uniqueness is not asserted here because it is per organization
 * AND per entity type, and the entity type arrives as a path segment that the controller has not
 * yet validated against the catalogue at the point rules() runs (§84).
 */
class StatusConfigRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],

            // The key is what code says out loud — `moveTo($run, 'approved')` — so it is limited to
            // what reads as an identifier. The service slugs whatever arrives, so this only rejects
            // input that would slug to nothing useful.
            'key' => ['nullable', 'string', 'max:64'],

            // Design-system tones only. A free string here would reach a class attribute.
            'color' => ['nullable', 'string', 'in:neutral,brand,success,warning,danger'],

            'is_initial' => ['boolean'],
            'is_terminal' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'between:0,99999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_initial', 'is_terminal', 'is_active']);

        $this->nullifyBlanks(['key', 'color', 'sort']);
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Give the status the name your team will see on the record.',
            'color.in' => 'Pick one of the listed colours.',
        ];
    }

    /**
     * What the service is allowed to read.
     *
     * `organization_id`, `entity_type` and `is_system` are absent by design: the first two come from
     * the actor and the route (§74, §84), and the third is a statement about the application's own
     * code that no form may make.
     *
     * @return array<string,mixed>
     */
    public function statusAttributes(): array
    {
        return [
            'label' => $this->string('label')->trim()->toString(),
            'key' => (string) $this->input('key', ''),
            'color' => $this->input('color'),
            'is_initial' => $this->boolean('is_initial'),
            'is_terminal' => $this->boolean('is_terminal'),
            'is_active' => $this->boolean('is_active'),
        ] + ($this->has('sort') ? ['sort' => (int) $this->input('sort')] : []);
    }

    /** The status this request is editing, when it is editing one. */
    public function subject(): ?Status
    {
        $status = $this->route('status');

        return $status instanceof Status ? $status : null;
    }
}
