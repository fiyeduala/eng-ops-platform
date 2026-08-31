<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Submittal;
use Illuminate\Validation\Rule;

/**
 * Put one line on a submittal package.
 *
 * `document_version_id` IS OPTIONAL HERE, and that is the difference from a transmittal item. A
 * transmittal is a record of files that left the building, so every line must name a revision. A
 * submittal package routinely contains things this platform does not hold a file for — a physical
 * sample, a mock-up panel, a manufacturer's certificate that arrived as a scan somebody has not
 * registered yet. Refusing those lines would push half of a real package into an email, which is
 * the failure the register exists to end. Where a revision IS named, the controller asks the
 * document's own policy before it is accepted (§67).
 *
 * `review_code` IS NOT ACCEPTED. Per-item codes are stamped by the reviewer through
 * `submittal.review`, and a form that could post one would let the submitting party mark their own
 * lines approved (§84). `description` is what the submitter writes; the verdict is not theirs.
 */
class SubmittalItemRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:300'],

            'document_version_id' => [
                'nullable', 'integer', $this->existsInTenant('document_versions'),

                // The same revision twice in one package reads, to a reviewer, as two things to
                // review. There is a unique index behind this; the rule exists so a double-clicked
                // picker produces a sentence rather than a constraint violation on an error page.
                Rule::unique('submittal_items', 'document_version_id')
                    ->where('submittal_id', $this->submittalId()),
            ],

            // The submitter's own reference — a specification clause, a drawing number, a
            // manufacturer's part number. Free text because it names something outside this system.
            'reference' => ['nullable', 'string', 'max:120'],

            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['document_version_id', 'reference', 'quantity', 'remarks', 'sort']);
    }

    public function messages(): array
    {
        return [
            'document_version_id.unique' => 'That revision is already in this package.',
            'description.required' => 'Say what this line is. A reviewer has to be able to read the package as a list.',
        ];
    }

    /**
     * The package being added to, as an id.
     *
     * Taken from the BOUND ROUTE MODEL, never from the body — the binding has already passed
     * through the tenant scope, so the uniqueness check cannot be pointed at another
     * organization's submittal by adding a field to the form (§84).
     */
    private function submittalId(): int
    {
        $submittal = $this->route('submittal');

        return $submittal instanceof Submittal
            ? (int) $submittal->getKey()
            : (int) $submittal;
    }

    public function versionId(): ?int
    {
        $id = $this->validated()['document_version_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed> */
    public function itemAttributes(): array
    {
        $data = $this->validated();

        unset($data['document_version_id']);

        return $data;
    }
}
