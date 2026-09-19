<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\PermissionCatalogue;

/**
 * Permit one move between two states (§63, §84).
 *
 * THE PERMISSION MUST BE ONE THE PLATFORM ACTUALLY HAS. StatusMachine::permits() asks the access
 * engine whether the actor holds `permission_key`, and an actor holds only keys the catalogue
 * declares — so a mistyped `paryoll.approve` does not make the move stricter, it makes it
 * impossible, and the register quietly loses a step nobody can take. Worse, the administrator who
 * typed it has every reason to believe they have secured the move. Constraining the field to the
 * catalogue turns a silent dead end into a select box.
 *
 * `from_status_id` MAY BE ABSENT and that is meaningful rather than sloppy: a null `from` is the
 * "from anywhere" row, which is how Cancel is expressed without drawing an arrow out of every
 * single state. StatusMachine::find() already prefers an exact from→to row over it, so the two
 * kinds coexist safely.
 *
 * Both ids are validated for shape only. Whether they belong to this company and this register is
 * settled in StatusConfigService against a scoped query, because an id that exists is not an id
 * the sender is entitled to (§75, §84).
 */
class StatusTransitionRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'to_status_id' => ['required', 'integer'],

            // '' and 'any' both mean "from anywhere"; a select box cannot post null.
            'from_status_id' => ['nullable'],

            'label' => ['nullable', 'string', 'max:60'],
            'permission_key' => ['nullable', 'string', 'in:'.implode(',', PermissionCatalogue::keys())],
            'requires_comment' => ['boolean'],
            'sort' => ['nullable', 'integer', 'between:0,99999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['requires_comment']);

        $this->nullifyBlanks(['label', 'permission_key', 'sort']);
    }

    public function messages(): array
    {
        return [
            'to_status_id.required' => 'Choose the status this move leads to.',
            'permission_key.in' => 'That is not a permission this platform grants, so nobody could ever hold it.',
        ];
    }

    /** @return array<string,mixed> */
    public function transitionAttributes(): array
    {
        return [
            'from_status_id' => $this->input('from_status_id'),
            'to_status_id' => $this->input('to_status_id'),
            'label' => $this->input('label'),
            'permission_key' => $this->input('permission_key'),
            'requires_comment' => $this->boolean('requires_comment'),
        ] + ($this->has('sort') ? ['sort' => (int) $this->input('sort')] : []);
    }
}
