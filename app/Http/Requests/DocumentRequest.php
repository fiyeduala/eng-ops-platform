<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Validation\Rule;

/**
 * Raise or amend a document (§26).
 *
 * The usual absences, each of which would be a privilege escalation if accepted (§84):
 *
 *   `number`             — the numbering engine's, and unique per tenant. A supplied number would
 *                          let somebody impersonate an existing drawing.
 *   `status_id`          — moves only through StatusMachine's configured whitelist.
 *   `current_version_id` — the register's pointer to "what this number means today". Set by
 *                          DocumentVersionService when a revision is approved, never by a form.
 *   `locked_by` / `lock_*` — the check-out columns. A form that could write them could unlock a
 *                          document somebody else is editing.
 *   `organization_id`    — from the session, like everywhere.
 *
 * `confidentiality` IS accepted, because classifying a document is an ordinary editorial act. It is
 * also a Gate 5 input, which is why DocumentPolicy is consulted with the record in hand before this
 * payload is applied: raising a document's confidentiality is allowed, and the person doing it has
 * to be able to see it afterwards.
 */
class DocumentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:5000'],

            // Nullable, because a document raised before its register has been decided is a normal
            // Tuesday. The category supplies numbering, revision scheme and route when it is set.
            'document_category_id' => ['nullable', 'integer', $this->existsInTenant('document_categories', 'id', true)],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // Who is answerable for it, which is not necessarily who typed it in.
            'owner_id' => ['nullable', 'integer', $this->existsInTenant('users', 'id', true)],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            // Native or file-based, and fixed at creation: a native document has content rows and a
            // file-based one has files, and switching afterwards would orphan one or the other.
            'kind' => [$this->document() === null ? 'required' : 'nullable', Rule::enum(DocumentKind::class)],

            'is_controlled' => ['boolean'],
            'retention_until' => ['nullable', 'date'],
            'next_review_on' => ['nullable', 'date'],

            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],

            // Only on create, and only for native documents. Seeds the first revision's body.
            'template_id' => ['nullable', 'integer', $this->existsInTenant('templates', 'id', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'document_category_id', 'project_id', 'org_unit_id', 'discipline_id',
            'owner_id', 'template_id', 'retention_until', 'next_review_on',
        ]);

        $this->normaliseBooleans(['is_controlled']);

        $this->merge([
            'confidentiality' => $this->input('confidentiality') ?: Confidentiality::Internal->value,
        ]);
    }

    /**
     * The payload minus the fields the services own.
     *
     * @return array<string,mixed>
     */
    public function documentAttributes(): array
    {
        $data = $this->validated();

        unset($data['template_id']);

        // `kind` is immutable after creation — see the rules above.
        if ($this->document() !== null) {
            unset($data['kind']);
        }

        return $data;
    }

    public function templateId(): ?int
    {
        $id = $this->validated()['template_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    private function document(): mixed
    {
        return $this->route('document');
    }
}
