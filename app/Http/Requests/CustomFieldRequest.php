<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CustomField;
use App\Support\CustomFieldCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape-checking for the field builder.
 *
 * Whether a key may change, whether a type may change, whether a field may be made required —
 * none of that is here. Those answers depend on how many records have already answered, and they
 * must hold for an importer as well as a form, so they live in CustomFieldService.
 *
 * `entity_type` IS validated here, against the catalogue rather than a free string, because it is
 * the one field on this form that decides which register a definition attaches to (§84).
 */
class CustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // CustomFieldController consults CustomFieldPolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(CustomFieldCatalogue::keys())],
            'label' => ['required', 'string', 'max:120'],

            // Nullable because the service derives one from the label. A non-developer must not
            // have to invent a machine name to add a field (§73).
            'key' => ['nullable', 'string', 'max:64'],

            'type' => ['required', 'string', Rule::in(array_keys(CustomField::TYPES))],
            'option_list_key' => ['nullable', 'string', 'max:64'],
            'help' => ['nullable', 'string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:120'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'is_required' => ['nullable', 'boolean'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'applies_when_key' => ['nullable', 'string', 'max:64'],
            'applies_when_value' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * organization_id and sort are absent by design — the first is the platform's (§74), the
     * second is set by the order of the rows on the screen.
     *
     * @return array<string,mixed>
     */
    public function fieldAttributes(): array
    {
        return $this->safe()->only([
            'entity_type', 'label', 'key', 'type', 'option_list_key', 'help', 'placeholder',
            'default_value', 'is_required', 'is_filterable', 'is_active',
            'applies_when_key', 'applies_when_value',
        ]);
    }
}
