<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Assign roles to an account, optionally pinning each assignment to one org unit (§10).
 *
 * Pinning is what makes a bounded administrator possible: "Site Administrator, but only for the
 * Lagos Yard". The pin narrows every grant the role carries, so the same role definition serves a
 * company-wide administrator and a departmental one without a second role existing.
 *
 * This class validates shape only. Whether the actor may hand out a given role — and in
 * particular whether they hold every permission inside it — is decided in the controller against
 * RolePolicy, because it is an authorization question, not a formatting one.
 */
class UserRolesRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            // Absent means "remove every role", which is a legitimate thing to submit.
            'roles' => ['present', 'array'],
            'roles.*' => ['integer', $this->existsInTenant('roles', 'id', activeOnly: true)],

            // Keyed by role id: scope_type[7] = 'org_unit', scope_id[7] = 12
            'scope_type' => ['array'],
            'scope_type.*' => ['nullable', Rule::in(['org_unit'])],

            'scope_id' => ['array'],
            'scope_id.*' => ['nullable', 'integer', $this->existsInTenant('org_units')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('scope_type', []) as $roleId => $type) {
                $id = $this->input('scope_id.'.$roleId);

                // A pin with no target silently becomes an unpinned, organization-wide
                // assignment — the exact opposite of what the administrator selected.
                if ($type !== null && $type !== '' && $id === null) {
                    $validator->errors()->add(
                        'scope_id.'.$roleId,
                        'Choose the unit this assignment is limited to.'
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'roles' => array_values(array_filter(
                (array) $this->input('roles', []),
                fn ($id) => $id !== null && $id !== ''
            )),
        ]);
    }

    /**
     * The submitted assignments, normalised to role id => ['scope_type' => ?string, 'scope_id' => ?int].
     *
     * A pin naming a role that was not ticked is discarded rather than honoured: a scope without
     * an assignment is meaningless, and keeping it would let a stale hidden field resurrect a role
     * the administrator just removed.
     *
     * @return array<int,array{scope_type:?string,scope_id:?int}>
     */
    public function assignments(): array
    {
        $validated = $this->validated();
        $out = [];

        foreach ($validated['roles'] as $roleId) {
            $roleId = (int) $roleId;
            $type = $validated['scope_type'][$roleId] ?? null;
            $id = $validated['scope_id'][$roleId] ?? null;

            $out[$roleId] = [
                'scope_type' => $type !== '' ? $type : null,
                'scope_id' => $type !== null && $type !== '' && $id !== null ? (int) $id : null,
            ];
        }

        return $out;
    }
}
