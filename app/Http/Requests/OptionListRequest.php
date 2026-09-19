<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-checking for the option-list editor.
 *
 * Whether a key may CHANGE, whether a value is frozen, whether a list may be deleted — none of
 * that is here. Those rules must hold for an importer and a seeder as well as a form, so they live
 * in OptionListService where every caller meets them.
 */
class OptionListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // OptionListController consults OptionListPolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],

            // Nullable because the service derives one from the name when it is left blank — a
            // non-developer setting up a company should not have to invent a machine name (§73).
            'key' => ['nullable', 'string', 'max:64'],

            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * organization_id and is_system are absent by design — the first is the platform's (§74) and
     * the second is a statement about code, not a checkbox.
     *
     * @return array<string,mixed>
     */
    public function listAttributes(): array
    {
        return $this->safe()->only(['name', 'key', 'description', 'is_active']);
    }
}
