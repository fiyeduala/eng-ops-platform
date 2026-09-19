<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shape-checking for one option. The rules that protect stored data are in OptionListService. */
class OptionListItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // OptionListController consults OptionListPolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:160'],
            'value' => ['nullable', 'string', 'max:64'],

            // A design-system token, never raw CSS. Enumerated here AND whitelisted again in the
            // service, because this string reaches a class attribute and one gate on the way to a
            // class attribute is not enough (§84).
            'color' => ['nullable', 'string', 'in:neutral,brand,success,warning,danger'],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string,mixed> */
    public function itemAttributes(): array
    {
        return $this->safe()->only(['label', 'value', 'color', 'is_active']);
    }
}
