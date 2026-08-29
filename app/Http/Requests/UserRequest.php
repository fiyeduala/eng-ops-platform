<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Create or edit a login account (§4, §6).
 *
 * Two absences are the point of this class.
 *
 * There is no `password` field. An administrator does not choose anyone's password; creating an
 * account issues a reset link and the holder sets their own. An administrator who typed the
 * password would know it, which turns "create a user" into "create a user I can sign in as", and
 * §92's ban on default passwords into a formality.
 *
 * There is no `is_super_admin`, no `organization_id` and no role field either. The first would be
 * self-promotion through a hidden input; the second is taken from the actor's own context (§74);
 * the third is a different authority entirely (`role.assign`), handled by its own route so that
 * granting access and correcting a phone number cannot be the same action (§10).
 */
class UserRequest extends TenantRequest
{
    public function rules(): array
    {
        $user = $this->target();

        return [
            'name' => ['required', 'string', 'max:120'],

            // Unique per organization, never globally. Two companies may legitimately employ
            // people whose addresses collide, and a global index would let anyone probe for the
            // existence of an account at another tenant.
            'email' => [
                'required', 'email', 'max:150',
                $this->uniqueInTenant('users', 'email', $user?->id),
            ],

            'username' => [
                'nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/',
                $this->uniqueInTenant('users', 'username', $user?->id),
            ],

            'phone' => ['nullable', 'string', 'max:40'],

            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'locale' => ['nullable', 'string', 'max:8', Rule::in(['en'])],

            // Disabling an account is how someone is off-boarded without destroying the trail of
            // what they approved. It is not the same as deleting them.
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        $this->nullifyBlanks(['username', 'phone', 'timezone', 'locale']);
        $this->normaliseBooleans(['is_active']);
    }

    public function attributes(): array
    {
        return [
            'is_active' => 'account status',
        ];
    }

    private function target(): ?User
    {
        $bound = $this->route('user');

        return $bound instanceof User ? $bound : null;
    }
}
