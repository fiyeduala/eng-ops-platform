<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ApplicantService;

/**
 * An application.
 *
 * THE CV IS VALIDATED TWICE, ON PURPOSE. Here, so the person uploading gets a clear message, and
 * again in ApplicantService against the file's real MIME type — a rule on the request checks what
 * the browser claimed, and the browser is the thing being defended against (§84).
 */
class ApplicantRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cv' => [
                'nullable', 'file',
                'max:'.(int) (ApplicantService::MAX_FILE_BYTES / 1024),
                'mimes:'.implode(',', ApplicantService::ALLOWED_EXTENSIONS),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['email', 'phone', 'source', 'notes']);
    }

    public function messages(): array
    {
        return [
            'cv.mimes' => 'A CV must be a PDF, a Word document or a scan.',
            'cv.max' => 'A CV may be at most 8MB.',
        ];
    }
}
