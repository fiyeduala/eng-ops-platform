<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Engines\Document\FileVault;
use App\Engines\Document\RevisionSequencer;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Validation\Rule;

/**
 * Configure a register (§26).
 *
 * This form decides how a whole class of documents behaves — how they are numbered, how revisions
 * are labelled, what may be uploaded, which approval route they take. It is administration, and
 * DocumentCategoryPolicy gates it on `document_category.manage`.
 *
 * `allowed_extensions` DELIBERATELY CANNOT WIDEN THE EXECUTABLE BLACKLIST. Whatever an administrator
 * types here, FileVault refuses `.php`, `.exe`, `.sh` and their friends unconditionally — that list
 * is a constant in the class, not configuration (§67). This field narrows an already-safe set; it
 * never grants. The validation below rejects a forbidden extension outright so the administrator
 * learns that at the form rather than discovering it on the first upload.
 */
class DocumentCategoryRequest extends TenantRequest
{
    public function rules(): array
    {
        $id = $this->route('document_category')?->getKey();

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],

            'parent_id' => ['nullable', 'integer', $this->existsInTenant('document_categories')],

            'revision_scheme' => ['required', Rule::in(array_keys(RevisionSequencer::SCHEMES))],
            'draft_revision_prefix' => ['nullable', 'string', 'max:8'],

            // The numbering engine's pattern for this register, e.g. {PROJECT}-{DISC}-{SEQ:4}.
            'numbering_pattern' => ['nullable', 'string', 'max:200'],

            'allowed_extensions' => ['nullable', 'array', 'max:40'],
            'allowed_extensions.*' => [
                'string', 'max:10', 'regex:/^[a-z0-9]+$/i',
                Rule::notIn(FileVault::FORBIDDEN_EXTENSIONS),
            ],
            'max_upload_mb' => ['nullable', 'integer', 'min:1', 'max:512'],

            'default_confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'default_kind' => ['required', Rule::enum(DocumentKind::class)],

            // The route every document in this register enters. Nullable: plenty of registers —
            // vendor data, correspondence received — hold documents nobody approves.
            'workflow_id' => ['nullable', 'integer', $this->existsInTenant('workflows')],

            'is_controlled' => ['boolean'],
            'requires_approval' => ['boolean'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],

            'slug' => ['nullable', 'string', 'max:140', $this->uniqueInTenant('document_categories', 'slug', $id)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'parent_id', 'code', 'workflow_id', 'max_upload_mb', 'retention_months',
            'draft_revision_prefix', 'numbering_pattern', 'slug',
        ]);

        $this->normaliseBooleans(['is_controlled', 'requires_approval', 'is_active']);

        // A register that requires no approval keeps no route, whatever the select still held when
        // the box was unticked. Leaving a stale workflow_id behind would put the register one
        // checkbox away from routing documents nobody meant to route, and would leave the show page
        // saying "no approval required" directly above the name of an approval chain.
        if ($this->boolean('requires_approval') === false) {
            $this->merge(['workflow_id' => null]);
        }

        // Extensions arrive from a comma-separated text field in the UI. Normalising here rather
        // than in the controller keeps the array rules above meaningful for both shapes.
        $extensions = $this->input('allowed_extensions');

        if (is_string($extensions)) {
            $this->merge([
                'allowed_extensions' => collect(explode(',', $extensions))
                    ->map(fn (string $value) => mb_strtolower(trim($value, " \t\n\r\0\x0B.")))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'allowed_extensions.*.not_in' => 'That file type can never be uploaded, whatever the register allows: '
                .'executable and script files are refused by the platform itself.',
        ];
    }
}
