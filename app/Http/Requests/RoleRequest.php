<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\Enums\AccessScope;
use App\Support\PermissionCatalogue;
use Illuminate\Validation\Rule;

/**
 * Define a role and the grants it carries (§5, §6).
 *
 * The grants half of this form is submitted on two axes, matching the access model rather than
 * flattening it (docs/04-access-model.md §5):
 *
 *   scope[document.approve]   = 'department'   — how far along the organizational ladder
 *   project[document.approve] = '1'            — and additionally, everywhere on their projects
 *
 * A single ordered dropdown could not express "their whole department, plus project-wide on the
 * projects they belong to", which is the ordinary shape of a discipline lead's access. Two
 * controls that union is the honest representation, and it is what the resolver already computes.
 *
 * `is_system` is not accepted from input, and neither is `organization_id`. What this class does
 * NOT decide is whether the actor is allowed to hand out the permissions they have ticked — that
 * is RolePolicy::editGrants, checked in the controller, because it is an authorization question.
 */
class RoleRequest extends TenantRequest
{
    public function rules(): array
    {
        $role = $this->target();

        return [
            'name' => ['required', 'string', 'max:100'],

            // Stable machine name. Used by templates and tests to find a role after somebody has
            // renamed it, which is why it is not simply derived from the name on every save.
            'slug' => [
                'nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                $this->uniqueInTenant('roles', 'slug', $role?->id),
            ],

            'description' => ['nullable', 'string', 'max:500'],

            // Display and ordering only. A role does not gain access by having a high level —
            // see the note on Role::$level. Kept because org charts read better sorted.
            'level' => ['nullable', 'integer', 'between:1,100'],

            'is_active' => ['boolean'],

            // --- grants ---------------------------------------------------------------------

            'scope' => ['array'],
            'scope.*' => ['nullable', Rule::in(self::organizationalScopes())],

            'project' => ['array'],
            'project.*' => ['nullable'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => mb_strtolower(trim($this->input('slug')))]);
        }

        $this->nullifyBlanks(['slug', 'description']);
        $this->normaliseBooleans(['is_active']);
    }

    /**
     * The role's own attributes, without the grants half of the form.
     *
     * @return array<string,mixed>
     */
    public function attributesOnly(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['name', 'slug', 'description', 'level', 'is_active']),
        );
    }

    /**
     * The submitted grants, as Role::syncGrants() expects them.
     *
     * Unknown permission keys are dropped rather than rejected. The catalogue is the authority on
     * what exists (§5); a key that is not in it is either a stale form from before a deployment or
     * something somebody typed into the request by hand, and neither should become a grant.
     *
     * @return array<string,array<int,string>> permission key => scopes
     */
    public function grants(): array
    {
        $validated = $this->validated();
        $scopes = (array) ($validated['scope'] ?? []);
        $projects = (array) ($validated['project'] ?? []);
        $known = array_flip(PermissionCatalogue::keys());

        $out = [];

        foreach ($scopes as $key => $scope) {
            if (! isset($known[$key]) || $scope === null || $scope === '') {
                continue;
            }

            $out[(string) $key][] = (string) $scope;
        }

        foreach ($projects as $key => $on) {
            if (! isset($known[$key]) || ! filter_var($on, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            // Project reach is additive, never a replacement: a lead may hold their department on
            // the organizational axis AND project-wide on the project axis, and see the union.
            $out[(string) $key][] = AccessScope::Project->value;
        }

        return $out;
    }

    /**
     * Every scope on the organizational axis. `project` is excluded on purpose: it arrives from
     * its own checkbox, and accepting it here would let one control silently occupy both axes.
     *
     * @return array<int,string>
     */
    private static function organizationalScopes(): array
    {
        return array_values(array_map(
            fn (AccessScope $scope) => $scope->value,
            array_filter(AccessScope::cases(), fn (AccessScope $scope) => $scope->isOrganizational()),
        ));
    }

    private function target(): ?Role
    {
        $bound = $this->route('role');

        return $bound instanceof Role ? $bound : null;
    }
}
