<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Ncr;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Raise a non-conformance, or amend one not yet answered.
 *
 * `requirement` IS REQUIRED AND `description` IS NOT. That is the wrong way round everywhere else
 * in this application and it is deliberate here. "The welds are bad" is an opinion; "AWS D1.1
 * Table 6.1 requires undercut not exceeding 1mm, measured 2.5mm" is a finding — and only the second
 * can be argued about, dispositioned, or defended two years later by somebody holding a failed
 * component. An NCR that cannot name the requirement it breaches is a complaint.
 *
 * THE DISPOSITION IS NOT ON THIS FORM. Rework, repair, use-as-is, reject and regrade are the
 * engineering answer, they carry their own permission and their own endpoint, and NcrService refuses
 * to let the person who detected the non-conformance decide that it can be left as it is — which is
 * what a disposition field on an edit form would allow. The same separation change requests use, and
 * for the same reason: this is the record that moves money (§84).
 *
 * `cost_impact` IS PLAIN AND UNSIGNED-BY-CONVENTION. There is no currency column, because §47
 * forbids baking in country-specific financial assumptions; the company's own books say what the
 * number is in.
 *
 * NOT ACCEPTED: `number`, `status_id`, `response*`, `disposition*`, `verified_*`, `closed_*`,
 * `organization_id`.
 */
class NcrRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // Nullable: plenty of non-conformances are found by walking past something, not at a
            // called inspection. When one is linked, the register can answer "which inspections
            // produced findings", which is how an ITP earns its keep.
            'quality_inspection_id' => ['nullable', 'integer', $this->existsInTenant('quality_inspections')],

            'source' => ['nullable', Rule::in($lists->keys('ncr.sources', Ncr::DEFAULT_SOURCES))],
            'severity' => ['nullable', Rule::in($lists->keys('ncr.severities', Ncr::DEFAULT_SEVERITIES))],
            'category' => ['nullable', Rule::in($lists->keys('ncr.categories', Ncr::DEFAULT_CATEGORIES))],

            'location' => ['nullable', 'string', 'max:200'],

            'requirement' => ['required', 'string', 'max:10000'],

            // Who the non-conformance is against — a subcontractor, a supplier, a fabricator. Free
            // text rather than a foreign key, because this platform does not hold a vendor ledger
            // and inventing half of one here would be worse than a name.
            'party_name' => ['nullable', 'string', 'max:200'],

            'detected_on' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:detected_on'],

            'cost_impact' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'project_id', 'discipline_id', 'org_unit_id', 'quality_inspection_id',
            'source', 'severity', 'category', 'location', 'party_name', 'detected_on',
            'required_by', 'cost_impact',
        ]);
    }

    public function messages(): array
    {
        return [
            'requirement.required' => 'Quote the requirement that has not been met — the clause, the drawing, the specification. Without it this is a complaint rather than a non-conformance.',
            'required_by.after_or_equal' => 'A response cannot be due before the non-conformance was found.',
        ];
    }

    /** @return array<string,mixed> */
    public function ncrAttributes(): array
    {
        return $this->validated();
    }
}
