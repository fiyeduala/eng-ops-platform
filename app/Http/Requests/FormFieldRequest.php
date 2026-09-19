<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FormField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape-checking for one question on a form.
 *
 * WHAT THIS CLASS DOES NOT DECIDE, and the list matters because it is longer than the list of what
 * it does: whether the version is still editable, whether this key is free, whether a repeating
 * table may sit inside another one, whether a published question may be reworded or re-keyed, and
 * whether a choice question has a list to draw on. Every one of those depends on stored data and
 * must hold for any caller — an importer, a seeder, a future API — so FormDefinitionService owns
 * them. A rule enforced only in a request class is a rule enforced only on the path somebody
 * happened to look at.
 *
 * `type` IS validated against FormField::TYPES here as well as in the service, because it is the
 * validation boundary for every answer this question will ever collect (§84) and a free string
 * reaching a `match` is a bug waiting for its input.
 *
 * `config` IS DELIBERATELY LOOSE HERE AND STRICT IN THE SERVICE. The per-type settings differ by
 * type, so validating them here would mean restating the type table in a second place and letting
 * the two drift. This class checks that it is a flat map of short scalars; configFor() keeps only
 * the keys that type actually has, which is what stops `config[is_required]` being stored at all.
 */
class FormFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // FormFieldController consults FormPolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],

            // Nullable: derived from the label when blank (§73).
            'key' => ['nullable', 'string', 'max:64'],

            'type' => ['required', 'string', Rule::in(array_keys(FormField::TYPES))],
            'option_list_key' => ['nullable', 'string', 'max:64'],
            'help' => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:120'],
            'default_value' => ['nullable', 'string', 'max:500'],
            'is_required' => ['nullable', 'boolean'],

            // The id of the repeating table this is a column of. Bounded to an integer here and
            // re-read against THIS version in the service, which is what stops a column being
            // hung off a table on another form.
            'parent_field_id' => ['nullable', 'integer'],

            'applies_when_key' => ['nullable', 'string', 'max:64'],
            'applies_when_value' => ['nullable', 'string', 'max:120'],

            'config' => ['nullable', 'array', 'max:12'],
            'config.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string,mixed> */
    public function fieldAttributes(): array
    {
        return $this->safe()->only([
            'label', 'key', 'type', 'option_list_key', 'help', 'placeholder', 'default_value',
            'is_required', 'parent_field_id', 'applies_when_key', 'applies_when_value', 'config',
        ]);
    }
}
