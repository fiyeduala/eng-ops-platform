<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transmittal;
use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;

/**
 * Issue a formal record of documents leaving the organization (§30).
 *
 * A transmittal is the difference between "I emailed you the drawings" and a dated, numbered record
 * of exactly which revisions went to which party, for what purpose, and whether they confirmed
 * receipt. Contractually it is often the only evidence that matters, which is why almost everything
 * on this form is about identifying the *other side* precisely.
 *
 * THE RECIPIENT IS DELIBERATELY FREE TEXT WITH AN OPTIONAL LINK. `to_name` is required; `to_party_type`
 * and `to_party_id` are optional and point at a client or vendor record when one exists. Transmittals
 * go to people the system has never heard of — a regulator, a joint-venture partner, a client's
 * appointed reviewer — and a form that could only address existing records would push users to
 * invent vendor entries just to send a letter.
 *
 * NOT ACCEPTED: `number`, `issued_at`, `issued_by`, `acknowledged_at`, `acknowledged_by_name`,
 * `status_id`, `organization_id`. Issuing is a one-way door with its own permission and endpoint,
 * and `issued_at` is the publication column the access engine reads — a form that could set it would
 * be a form that could publish a draft to a wider audience without passing through issue() (§84).
 */
class TransmittalRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:250'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // The addressee. Free text by design; the link is a convenience, not a constraint.
            'to_name' => ['required', 'string', 'max:200'],
            'to_party_type' => ['nullable', Rule::in(['client', 'vendor', 'org_unit'])],
            'to_party_id' => ['nullable', 'integer', 'required_with:to_party_type'],
            'to_attention' => ['nullable', 'string', 'max:200'],
            'to_email' => ['nullable', 'email:rfc', 'max:200'],
            'to_address' => ['nullable', 'string', 'max:1000'],

            'purpose' => ['required', Rule::in(array_keys(Transmittal::PURPOSES))],
            'transport' => ['nullable', Rule::in(array_keys(Transmittal::TRANSPORTS))],

            // Inherited by the cover sheet and used by the access engine. A transmittal is never
            // less confidential than the documents on it — the service tightens this at issue time.
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            'cover_note' => ['nullable', 'string', 'max:5000'],

            'due_response_on' => ['nullable', 'date', 'after_or_equal:today'],
            'requires_acknowledgement' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['requires_acknowledgement']);
        $this->nullifyBlanks([
            'project_id', 'org_unit_id', 'to_party_type', 'to_party_id', 'to_attention',
            'to_email', 'to_address', 'transport', 'cover_note', 'due_response_on',
        ]);
    }

    public function messages(): array
    {
        return [
            'to_party_id.required_with' => 'Choose which client, vendor or unit this is addressed to, '
                .'or leave the type blank and just type the name.',
        ];
    }

    /** @return array<string,mixed> */
    public function transmittalAttributes(): array
    {
        return $this->validated();
    }
}
