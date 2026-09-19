<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-checking for a heading on a form.
 *
 * There is almost nothing to check, and that is the point worth recording: a section carries a
 * title and a sentence under it and nothing else, which is exactly why retitling one is allowed on
 * a published version. Nothing here is a promise to anybody who has already answered.
 *
 * `form_version_id` IS NOT ACCEPTED. The version a section belongs to comes from the route, is
 * bound to a model, and is re-read by the service before any write — never from the payload, which
 * is how a section from a draft would otherwise be posted onto somebody else's live edition (§84).
 */
class FormSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // FormSectionController consults FormPolicy
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string,mixed> */
    public function sectionAttributes(): array
    {
        return $this->safe()->only(['title', 'description']);
    }
}
