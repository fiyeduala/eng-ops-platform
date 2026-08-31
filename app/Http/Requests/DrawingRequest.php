<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Drawing;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Add or amend a line in the drawing register — the title block, not the file.
 *
 * Everything on this form is what would be printed in the bottom-right corner of a sheet: number,
 * title, sheet n of m, size, scale, revision, purpose, drawn by, checked by. The file itself is
 * never posted here. It lives in document control, and the register points at it with
 * `document_id`, which this form cannot set — attaching is its own endpoint so the act of putting a
 * confidential file behind a register entry passes through a policy that can see both records (§67).
 *
 * A DRAWING REGISTER IS ROUTINELY MAINTAINED BEFORE ANY FILE EXISTS. That is why `number` and
 * `title` are the only required fields: a discipline lead laying out a sheet list on day one knows
 * the numbers and titles and nothing else. Refusing a row until somebody picks a scale would push
 * the whole exercise back into a spreadsheet, which is the failure this module exists to end.
 *
 * `number` IS ACCEPTED HERE, unlike every other register in Phase 4. Drawing numbers are dictated
 * by a project's numbering convention and are often assigned by the client, so the generator
 * supplies a suggestion and the user overrides it. The uniqueness rule is per organization, which
 * is both the real guarantee and the reason a duplicate produces a sentence instead of a 500.
 *
 * NOT ACCEPTED: `status_id`, `document_id`, `organization_id`.
 */
class DrawingRequest extends TenantRequest
{
    public function rules(): array
    {
        $purposes = app(OptionList::class)->keys('engineering.drawing_purposes', Drawing::DEFAULT_PURPOSES);

        return [
            'number' => [
                'required', 'string', 'max:64',
                $this->uniqueInTenant('drawings', 'number', $this->drawingId()),
            ],
            'title' => ['required', 'string', 'max:300'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'deliverable_id' => ['nullable', 'integer', $this->existsInTenant('deliverables')],

            // "Sheet 3 of 7". Both nullable, because plenty of drawings are a single sheet and
            // nobody writes "1 of 1" unless the title block forces them to.
            'sheet_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'sheet_count' => ['nullable', 'integer', 'min:1', 'max:9999', 'gte:sheet_number'],

            'sheet_size' => ['nullable', Rule::in(Drawing::SHEET_SIZES)],

            // Free text: "1:50", "NTS", "As Shown" and "1:100 @ A1" are all things that legitimately
            // appear in a scale box, and a closed list would be wrong within a week.
            'scale' => ['nullable', 'string', 'max:32'],

            'revision' => ['nullable', 'string', 'max:16'],
            'purpose' => ['nullable', Rule::in($purposes)],
            'revision_date' => ['nullable', 'date'],

            'drawn_by' => ['nullable', 'integer', $this->existsInTenant('users')],
            'checked_by' => ['nullable', 'integer', $this->existsInTenant('users')],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'project_id', 'discipline_id', 'org_unit_id', 'deliverable_id', 'sheet_number',
            'sheet_count', 'sheet_size', 'scale', 'revision', 'purpose', 'revision_date',
            'drawn_by', 'checked_by',
        ]);
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'A drawing with that number is already in this register.',
            'sheet_count.gte' => 'A sheet set cannot have fewer sheets than the sheet you are on.',
        ];
    }

    /**
     * The drawing being edited, so the uniqueness rule ignores its own row.
     *
     * Read from the BOUND ROUTE MODEL, never from the body — the binding has already passed
     * through the tenant scope (§84).
     */
    private function drawingId(): ?int
    {
        $drawing = $this->route('drawing');

        return $drawing instanceof Drawing ? (int) $drawing->getKey() : null;
    }

    /** @return array<string,mixed> */
    public function drawingAttributes(): array
    {
        return $this->validated();
    }
}
