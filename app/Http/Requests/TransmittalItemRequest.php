<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transmittal;
use App\Models\TransmittalItem;
use Illuminate\Validation\Rule;

/**
 * Put one revision on a transmittal.
 *
 * `document_version_id` NAMES A REVISION, NOT A DOCUMENT. That is the whole point of the module: six
 * months from now the question is never "did we send them the layout drawing", it is "which revision
 * of the layout drawing did we send them on the 14th". Pointing at the document would answer with
 * whatever is current today, which is precisely the answer nobody needs.
 *
 * TWO CHECKS, BOTH DELIBERATE. This request confirms the revision exists in this tenant; the service
 * then re-checks `organization_id` against the transmittal's own before writing, and the controller
 * asks DocumentVersionPolicy whether this actor may see the parent document at all. A posted id is
 * an untrusted id however many times it has been looked up (§84, §75) — and a transmittal that could
 * carry a revision its sender cannot read would be an exfiltration route with a cover sheet on it.
 */
class TransmittalItemRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'document_version_id' => [
                'required', 'integer', $this->existsInTenant('document_versions'),

                // The same revision twice is not a package of two things — it is a register entry
                // that reads as if the recipient were sent something they were sent once. There is
                // a unique index behind this, and the index is the real guarantee; this rule exists
                // so a double-clicked picker produces a sentence somebody can act on rather than a
                // constraint violation on an error page.
                Rule::unique('transmittal_items', 'document_version_id')
                    ->where('transmittal_id', $this->transmittalId()),
            ],

            // Paper copies are still counted on real transmittals, and the count is contractual.
            'copies' => ['nullable', 'integer', 'min:1', 'max:999'],
            'media' => ['nullable', Rule::in(array_keys(TransmittalItem::MEDIA))],

            'remarks' => ['nullable', 'string', 'max:300'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_version_id.unique' => 'That revision is already on this transmittal.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['remarks', 'media', 'copies', 'sort']);
    }

    /**
     * The transmittal being added to, as an id.
     *
     * Taken from the BOUND ROUTE MODEL, never from the body. The binding has already been through
     * the tenant scope, so the uniqueness check below cannot be pointed at another organization's
     * transmittal by adding a field to the form (§84).
     */
    private function transmittalId(): int
    {
        $transmittal = $this->route('transmittal');

        return $transmittal instanceof Transmittal
            ? (int) $transmittal->getKey()
            : (int) $transmittal;
    }

    public function versionId(): int
    {
        return (int) $this->validated()['document_version_id'];
    }

    /** @return array<string,mixed> */
    public function itemAttributes(): array
    {
        $data = $this->validated();

        unset($data['document_version_id']);

        return $data;
    }
}
