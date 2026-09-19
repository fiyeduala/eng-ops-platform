<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-checking for a form's own details — its name, its key, what drawer it files under.
 *
 * NOT NAMED `FormRequest`, which would be the obvious name and is taken by the framework class
 * this extends. A file that has to alias its own parent is a file somebody eventually gets wrong.
 *
 * WHETHER THE KEY MAY CHANGE IS NOT DECIDED HERE. That depends on how many submissions already
 * name this form by it, which is a question about stored data rather than about the payload, and
 * it must hold for any other caller as well as for this one — so FormDefinitionService answers it.
 * What this class does is the part that is genuinely about the request: lengths, types, presence.
 *
 * `organization_id`, `created_by` and `is_active`-on-create are absent by design. The first two
 * are the platform's (§74); the third is not a decision anybody makes while typing a name.
 */
class FormDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // FormController consults FormPolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],

            // Nullable because the service derives one from the name. A safety officer building an
            // inspection sheet must not have to invent a machine name first (§73).
            'key' => ['nullable', 'string', 'max:64'],

            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string,mixed> */
    public function formAttributes(): array
    {
        return $this->safe()->only(['name', 'key', 'description', 'category', 'is_active']);
    }
}
